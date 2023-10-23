<?php

namespace ScriptoImportContributors\Form;

use Laminas\Form\Form;
use Omeka\Form\Element\PropertySelect;

class ConfigForm extends Form
{
    public function init()
    {
        $this->add([
            'name' => 'scriptoimportcontributors_annotation_property',
            'type' => PropertySelect::class,
            'options' => [
                'label' => 'Annotation property', // @translate
                'info' => 'In which annotation property to put contributors names', // @translate
                'empty_option' => 'None', // @translate
            ],
        ]);
        $this->add([
            'name' => 'scriptoimportcontributors_media_property',
            'type' => PropertySelect::class,
            'options' => [
                'label' => 'Media property', // @translate
                'info' => 'In which media property to put contributors names', // @translate
                'empty_option' => 'None', // @translate
            ],
        ]);

        $inputFilter = $this->getInputFilter();
        $inputFilter->add([
            'name' => 'scriptoimportcontributors_annotation_property',
            'allow_empty' => true,
        ]);
        $inputFilter->add([
            'name' => 'scriptoimportcontributors_media_property',
            'allow_empty' => true,
        ]);
    }
}
