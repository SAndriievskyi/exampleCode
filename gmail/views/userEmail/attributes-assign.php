<?php

/**
 * @var \app\components\View                                  $this
 * @var \app\models\processing\ChangeJuristicSourceAttraction $model
 * @var array                                                 $header
 * @var array                                                 $data
 */

use yii\helpers\Html;

$this->title = 'Выбор полей соответствия';

if (empty($this->params['breadcrumbs'])) {
    $this->params['breadcrumbs'][] = [
        'label' => \app\modules\gmail\models\processing\UserEmailProcessing::getSingularNominativeName(),
        'url'   => ['process'],
    ];
    $this->params['breadcrumbs'][] = $this->title;
}

?>
    <div class="alert alert-warning"><strong>Выберете нужное значение из выпадающего списка под названием колонки.</strong></div>
<?php
$form = \yii\widgets\ActiveForm::begin(['method' => 'post', 'enableClientScript' => false]);
echo $form->field($model, 'file_id');
$select = Html::tag('td', $form->field($model, 'fields[]',
    ['template' => '{input}', 'options' => ['class' => '']])->dropDownList($model->getAllowedFields(),
    ['prompt' => '(не грузим)']));
foreach ($header as $i => $value) {
    $header[$i]['headerSections']['fieldMap'] = $select;
}

echo \app\widgets\GridView\GridView::widget([
    'columns'            => $header,
    'layout'             => '{items}',
    'enableClientScript' => false,
    'headerSections'     => [
        'headerCell',
        'fieldMap',
    ],
    'dataProvider'       => new \yii\data\ArrayDataProvider([
        'allModels' => $data,
    ]),
    'options'            => [
        'class' => 'grid-view table-fixed',
    ],
]);
?>
    <div class="form-group m-top-5"><?= Html::submitButton('Далее', ['class' => 'btn btn-primary']); ?></div>
<?php

\yii\widgets\ActiveForm::end();
$this->registerViewAssets(
    __DIR__ . '/assets',
    [],
    ['style.css']
);