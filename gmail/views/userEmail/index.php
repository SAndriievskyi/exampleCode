<?php

use yii\helpers\Html;

$toolbarButtons[] = Html::a('Загрузить', ['load-csv'], [
    'class' => 'btn btn-primary submit-form pull-right',
    'style' => 'margin-left: 15px',
    'title' => 'Загрузить csv',
]);

require($this->findViewFile('index', $this->context));