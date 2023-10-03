<?php

namespace ScriptoImportContributors;

use Composer\Semver\Comparator;
use Doctrine\Common\Collections\Criteria;
use Omeka\Module\AbstractModule;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\EventManager\Event;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;
use Scripto\Entity\ScriptoMedia;
use ScriptoImportContributors\Form\ConfigForm;

class Module extends AbstractModule
{
    protected $importedScriptoMediaIds = [];

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(ScriptoMedia::class, 'entity.update.pre', [$this, 'onScriptoMediaUpdatePre']);
        $sharedEventManager->attach(ScriptoMedia::class, 'entity.update.post', [$this, 'onScriptoMediaUpdatePost']);
    }

    public function install(ServiceLocatorInterface $serviceLocator)
    {
        $this->createVocabulary($serviceLocator);
    }

    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $serviceLocator)
    {
        if (Comparator::lessThan($oldVersion, '0.2.0')) {
            $this->createVocabulary($serviceLocator);
        }
    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $forms = $services->get('FormElementManager');
        $form = $forms->get(ConfigForm::class);

        $form->setData([
            'scriptoimportcontributors_annotation_property' => $settings->get('scriptoimportcontributors_annotation_property'),
            'scriptoimportcontributors_media_property' => $settings->get('scriptoimportcontributors_media_property'),
        ]);

        return $renderer->formCollection($form, false);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $forms = $services->get('FormElementManager');
        $form = $forms->get(ConfigForm::class);
        $form->setData($controller->params()->fromPost());
        if (!$form->isValid()) {
            $controller->messenger()->addFormErrors($form);
            return false;
        }

        $formData = $form->getData();
        $settings->set('scriptoimportcontributors_annotation_property', $formData['scriptoimportcontributors_annotation_property']);
        $settings->set('scriptoimportcontributors_media_property', $formData['scriptoimportcontributors_media_property']);

        return true;
    }

    public function onScriptoMediaUpdatePre(Event $event)
    {
        // If ScriptoMedia::$importedHtml has been changed, we probably are in
        // an ImportProject job.
        // We keep the ScriptoMedia id so that we can import contributors for
        // it in the postUpdate event (see onScriptoMediaUpdatePost)
        $args = $event->getParam('LifecycleEventArgs');
        if ($args->hasChangedField('importedHtml')) {
            $id = $args->getEntity()->getId();
            $this->importedScriptoMediaIds[$id] = $id;
        }
    }

    public function onScriptoMediaUpdatePost(Event $event)
    {
        $args = $event->getParam('LifecycleEventArgs');
        $scriptoMedia = $args->getEntity();
        $id = $scriptoMedia->getId();
        if (!isset($this->importedScriptoMediaIds[$id])) {
            return;
        }

        unset($this->importedScriptoMediaIds[$id]);

        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $annotationPropertyId = $settings->get('scriptoimportcontributors_annotation_property');
        $mediaPropertyId = $settings->get('scriptoimportcontributors_media_property');
        if (!$annotationPropertyId && !$mediaPropertyId) {
            return;
        }

        $media = $scriptoMedia->getMedia();
        $property = $scriptoMedia->getScriptoItem()->getScriptoProject()->getProperty();
        $criteria = Criteria::create()->where(Criteria::expr()->eq('property', $property));
        $values = $media->getValues()->matching($criteria);
        if ($values->isEmpty()) {
            return;
        }

        $contributors = $this->queryContributors($scriptoMedia->getMediawikiPageTitle());
        if (empty($contributors)) {
            return;
        }

        // In a postUpdate event we cannot call EntityManager::flush, so we use
        // Connection directly
        $connection = $args->getEntityManager()->getConnection();

        if ($annotationPropertyId) {
            foreach ($values as $value) {
                // Create a value annotation
                $connection->executeStatement(
                    'INSERT INTO resource (is_public, created, resource_type) VALUES (?, ?, ?)',
                    [1, new \DateTime(), \Omeka\Entity\ValueAnnotation::class],
                    ['integer', 'datetime', 'string']
                );
                $connection->executeStatement('INSERT INTO value_annotation (id) VALUES(LAST_INSERT_ID())');
                $connection->executeStatement('UPDATE value SET value_annotation_id = LAST_INSERT_ID() WHERE id = ?', [$value->getId()]);

                // Add dcterms:contributor values to the new value annotation
                // LAST_INSERT_ID() return value is stable in a multiple-row INSERT
                // statement, so it's always equal to the value annotation id
                // See https://mariadb.com/kb/en/last_insert_id/
                $sql = 'INSERT INTO `value` (resource_id, property_id, type, `value`, is_public) VALUES ';
                $params = [];
                $sqlValues = [];
                foreach ($contributors as $contributor) {
                    $sqlValues[] = '(LAST_INSERT_ID(), ?, ?, ?, ?)';
                    $params = array_merge($params, [$annotationPropertyId, 'literal', $contributor['name'], 1]);
                }
                $sql .= implode(', ', $sqlValues);
                $connection->executeStatement($sql, $params);
            }
        }

        if ($mediaPropertyId) {
            $connection->executeStatement(
                'DELETE FROM value WHERE resource_id = ? AND property_id = ?',
                [$media->getId(), $mediaPropertyId]
            );

            $sql = 'INSERT INTO `value` (resource_id, property_id, type, `value`, is_public) VALUES ';
            $params = [];
            $sqlValues = [];
            foreach ($contributors as $contributor) {
                $sqlValues[] = '(?, ?, ?, ?, ?)';
                $params = array_merge($params, [$media->getId(), $mediaPropertyId, 'literal', $contributor['name'], 1]);
            }
            $sql .= implode(', ', $sqlValues);
            $connection->executeStatement($sql, $params);
        }
    }

    public function getConfig()
    {
        return require __DIR__ . '/config/module.config.php';
    }

    protected function queryContributors($title)
    {
        $client = $this->getServiceLocator()->get('Scripto\Mediawiki\ApiClient');
        $query = $client->request([
            'action' => 'query',
            'prop' => 'contributors',
            'titles' => $title,
        ]);
        $pages = $query['query']['pages'];
        $pageid = (int) array_key_first($pages);
        if ($pageid < 0) {
            throw new \Exception('Invalid page title');
        }

        $contributors = $pages[$pageid]['contributors'];

        return $contributors;
    }

    protected function createVocabulary(ServiceLocatorInterface $serviceLocator)
    {
        $connection = $serviceLocator->get('Omeka\Connection');
        $vocabularyId = $connection->fetchOne(
            'SELECT id FROM vocabulary WHERE prefix = ?',
            ['scriptoimportcontributors']
        );
        if (!$vocabularyId) {
            $connection->executeStatement(
                'INSERT INTO vocabulary (namespace_uri, prefix, label) VALUES (?, ?, ?)',
                ['https://github.com/biblibre/omeka-s-module-ScriptoImportContributors/', 'scriptoimportcontributors', 'Scripto Import Contributors']
            );
            $vocabularyId = $connection->fetchOne(
                'SELECT id FROM vocabulary WHERE prefix = ?',
                ['scriptoimportcontributors']
            );
        }

        $propertyId = $connection->fetchOne(
            'SELECT id FROM property WHERE vocabulary_id = ? AND local_name = ?',
            [$vocabularyId, 'transcriber']
        );
        if (!$propertyId) {
            $connection->executeStatement(
                'INSERT INTO property (vocabulary_id, local_name, label) VALUES (?, ?, ?)',
                [$vocabularyId, 'transcriber', 'Transcriber']
            );
        }
    }
}
