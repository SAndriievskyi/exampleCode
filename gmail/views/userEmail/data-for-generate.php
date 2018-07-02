<?php
/**
 * @var \app\components\View $this
 * @var \app\models\processing\ChangeJuristicSourceAttraction $model
 */
use kartik\widgets\ActiveForm;
use yii\helpers\Html;

$this->title = 'Выбор файла';

if (empty($this->params['breadcrumbs'])) {
    $this->params['breadcrumbs'][] = [
        'label' => Html::encode(
            \app\modules\gmail\models\processing\UserEmailProcessing::getSingularNominativeName()
        ),
        'url' => ['process'],
    ];
    $this->params['breadcrumbs'][] = $this->title;
}
$form = ActiveForm::begin(['method' => 'post', 'options' => ['enctype' => 'multipart/form-data']]);
echo $form->errorSummary($model, ['class' => 'alert alert-danger']);
if (Yii::$app->session->hasFlash('success')) {
    ?>
    <div class="alert alert-success">
        <p><?= Yii::$app->session->getFlash('success') ?></p>
    </div>
    <?php
}
if (Yii::$app->session->hasFlash('error')) {
    ?>
    <div class="alert alert-danger">
        <p><?= Yii::$app->session->getFlash('error') ?></p>
    </div>
    <?php
}
// Блок полей формы
$this->beginExtBlock('fields', true);
$fieldsOptions = $model->getFieldsOptions(); ?>

<?= $form->field($model, 'uploadFile'); ?>

<?php $this->endExtBlock() ?>
    <div class="form-group">
        <?= Html::submitButton('Далее', ['class' => 'btn btn-primary']) ?>
    </div>
<?php ActiveForm::end(); ?>