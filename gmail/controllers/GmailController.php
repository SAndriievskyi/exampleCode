<?php

namespace app\modules\gmail\controllers;

use app\models\document\Email;
use app\models\enum\DocumentStatus;
use app\modules\gmail\components\Manager;
use app\modules\gmail\models\ExchangeSetupGmail;
use yii\data\ArrayDataProvider;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;

/**
 * Планировщик задач для загрузки почты
 */
class GmailController extends Controller
{
    /**
     * @inheritdoc
     */
    public $layout = '/iframe';

    /**
     * @var bool|string право для доступа к списка моделей (index)
     */
    public $indexPermission = 'common.other.email.view';

    /**
     * @var bool|string право для доступа к редактированию модели (update)
     */
    public $updatePermission = 'common.other.email.update';

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return array_merge(
            parent::behaviors(),
            [
                'access' => [
                    'class' => AccessControl::class,
                    'rules' => [
                        [
                            'allow'   => true,
                            'actions' => ['spam', 'import-spam'],
                            'roles'   => [$this->indexPermission],
                        ],
                        [
                            'allow'   => true,
                            'actions' => ['set-spam'],
                            'roles'   => [$this->updatePermission],
                        ],
                    ],
                ],
                'verbs'  => [
                    'class'   => VerbFilter::class,
                    'actions' => [
                        'spam'     => ['GET'],
                        'set-spam' => ['GET'],
                    ],
                ],
            ]
        );
    }

    /**
     * Вывод данных из папки спам
     *
     * @param $exchangeSetupId
     *
     * @return string
     */
    public function actionSpam($exchangeSetupId)
    {
        $manager = new Manager();
        $manager->exchangeSetup = ExchangeSetupGmail::findOne($exchangeSetupId);
        $spam = $manager->getSpam();

        $dataProvider = new ArrayDataProvider([
            'allModels'  => $spam,
            'pagination' => false,
        ]);

        return $this->render('view', ['dataProvider' => $dataProvider]);
    }

    /**
     * Загрузка сообщений из папки спам
     *
     * @param string $exchangeSetupId
     *
     * @return boolean
     * @throws \yii\db\Exception
     * @throws null
     */
    public function actionImportSpam($exchangeSetupId)
    {
        $manager = new Manager();
        $manager->exchangeSetup = ExchangeSetupGmail::findOne($exchangeSetupId);
        $manager->importSpam(\Yii::$app->request->post('emailIds', []));

        return true;
    }

    /**
     * Пометка сообщения как спам
     *
     * @param string $id
     *
     * @return boolean
     * @throws \yii\base\Exception
     * @throws \yii\base\UserException
     * @throws \yii\db\Exception
     * @throws null
     */
    public function actionSetSpam($id)
    {
        $email = Email::findOne($id);
        $manager = new Manager();
        $manager->exchangeSetup = $email->exchangeSetup;
        $manager->setSpam($id);

        $email->scenario = Email::SCENARIO_DRAFT;
        $email->document_status_id = DocumentStatus::DELETED;
        $email->save();

        return true;
    }
}