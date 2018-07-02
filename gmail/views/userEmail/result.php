<?php
/**
 * @var \app\components\View $this
 * @var \app\models\processing\ChangeJuristicSourceAttraction $model
 */
use yii\helpers\Html;

$this->title = 'Результат';

if (empty($this->params['breadcrumbs'])) {
    $this->params['breadcrumbs'][] = [
        'label' => Html::encode(
            \app\modules\gmail\models\processing\UserEmailProcessing::getSingularNominativeName()
        ),
        'url' => ['process'],
    ];
    $this->params['breadcrumbs'][] = $this->title;
}
if (Yii::$app->session->hasFlash('success')) {
    ?>
    <div class="alert alert-info">
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
?>
<div class="form-group">
    <?= Html::a('Загрузить ещё раз', ['load-csv'], ['class' => 'btn btn-primary']) ?>
</div>