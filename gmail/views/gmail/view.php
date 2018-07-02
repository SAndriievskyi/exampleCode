<?php

use app\widgets\GridView\CheckboxColumn;
use app\widgets\GridView\GridView;
use yii\helpers\Json;

/** @var \yii\data\ArrayDataProvider $dataProvider */
/** @var \app\components\View $this */

$this->title = 'Спам';

$gridViewButtons[] = \yii\helpers\Html::a('Скачать',
    ['/gmail/gmail/import-spam', 'exchangeSetupId' => Yii::$app->user->getIdentity()->defaultEmailExchangeSetup->primaryKey],
    ['class' => 'btn btn-primary remove-from-spam', 'title' => 'Скачать из папки спам']);

$pjax = \yii\widgets\Pjax::begin();
echo GridView::widget(
    [
        'id'             => 'spam-grid-view',
        'dataProvider'   => $dataProvider,
        'toolbarButtons' => $gridViewButtons,
        'actionColumn'   => false,
        'serialColumn'   => [],
        'columns'        => [
            'checkbox' => [
                'class'           => CheckboxColumn::className(),
                'checkboxOptions' => function ($model) {
                    return ['class' => 'select-checkbox', 'data-id' => $model['id']];
                },
            ],
            'from:text:Отправитель',
            'to:text:Получатель',
            'subject:text:Тема',
            'body:text:Сообщение',
        ],
        'tableOptions'   => [
            'class' => 'table table-bordered table-hover',
        ],
    ]
);
\yii\widgets\Pjax::end();
$this->registerViewAssets(__DIR__ . '/assets', ['view.js'], [], [
    app\assets\JsUrlAsset::class,
    \app\assets\IframeDialogAsset::class,
]);
$options = Json::encode([
    'pjaxViewId'   => $pjax->id,
]);
$this->registerJs("SpamViewScript.init({$options});");