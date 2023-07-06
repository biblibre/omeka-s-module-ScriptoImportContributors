<?php

namespace ScriptoImportContributors;

use Doctrine\Common\Collections\Criteria;
use Omeka\Module\AbstractModule;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\EventManager\Event;
use Scripto\Entity\ScriptoMedia;

class Module extends AbstractModule
{
    protected $importedScriptoMediaIds = [];

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(ScriptoMedia::class, 'entity.update.pre', [$this, 'onScriptoMediaUpdatePre']);
        $sharedEventManager->attach(ScriptoMedia::class, 'entity.update.post', [$this, 'onScriptoMediaUpdatePost']);
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

        $contributorPropertyId = $connection->fetchOne('SELECT p.id FROM property p JOIN vocabulary v ON (p.vocabulary_id = v.id) WHERE v.prefix = ? AND p.local_name = ?', ['dcterms', 'contributor']);
        if (!$contributorPropertyId) {
            return;
        }

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
                $params = array_merge($params, [$contributorPropertyId, 'literal', $contributor['name'], 1]);
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
}
