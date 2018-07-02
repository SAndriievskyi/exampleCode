<?php

namespace app\modules\gmail\controllers;

use app\controllers\ActiveController;
use app\models\processing\CsvImport;
use app\models\reference\File;
use app\models\search\SearchInterface;
use app\modules\gmail\models\processing\UserEmailProcessing;
use app\modules\gmail\models\reference\UserEmail;
use app\modules\gmail\models\search\UserEmailSearch;
use Yii;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\web\BadRequestHttpException;
use yii\web\Response;
use yii\web\UploadedFile;

/**
 * Контролер почт пользователей
 */
class UserEmailController extends ActiveController
{
    /**
     * @inheritdoc
     */
    public $layout = '/iframe';

    /**
     * @inheritdoc
     */
    public $modelClass = UserEmail::class;

    /**
     * @inheritdoc
     */
    public $searchModelClass = UserEmailSearch::class;

    /**
     * @inheritdoc
     */
    public $indexPermission = 'common.gmail.userEmail.view';

    /**
     * @inheritdoc
     */
    public $createPermission = 'common.gmail.userEmail.update';

    /**
     * @inheritdoc
     */
    public $updatePermission = 'common.gmail.userEmail.update';

    /**
     * @inheritdoc
     */
    public $deletePermission = 'common.gmail.userEmail.update';

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return ArrayHelper::merge(
            parent::behaviors(),
            [
                'access' => [
                    'rules' => [
                        [
                            'allow'   => true,
                            'actions' => ['load-csv'],
                            'roles'   => [$this->updatePermission],
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * @inheritdoc
     */
    public function actions()
    {
        $actions = parent::actions();
        $actions['index']['view'] = '/userEmail/index';
        unset($actions['select']);

        return $actions;
    }

    /**
     * @inheritdoc
     */
    public function actionSelect($term = null)
    {
        if (Yii::$app->request->isAjax) {
            Yii::$app->response->format = Response::FORMAT_JSON;
            $query = UserEmail::find()
                ->active()
                ->limit(20);
            if ($term) {
                $query->andWhere([
                    'or',
                    ['ilike', 'name', $term],
                    ['ilike', 'email', $term],
                ]);
            }
            /** @var UserEmail[] $models */
            $models = $query->all();
            $result = [['id' => $term, 'text' => $term]];
            foreach ($models as $model) {
                $result[] = [
                    'id'   => $model->email,
                    'text' => Html::encode((string)$model),
                ];
            }

            return $result;
        }

        $searchModel = $this->searchModelClass;
        /** @var SearchInterface $searchModel */
        $searchModel = new $searchModel();

        return $this->renderUniversal($this->view ?: $this->id, [
            'searchModel'  => $searchModel,
            'dataProvider' => $searchModel->searchModels(Yii::$app->request->get()),
        ]);
    }

    /**
     * Загрузка данных из файла
     *
     * @return string
     * @throws BadRequestHttpException
     * @throws \Exception
     * @throws \yii\base\Exception
     * @throws \yii\base\UserException
     * @throws \yii\db\Exception
     * @throws null
     */
    public function actionLoadCsv()
    {
        $model = new UserEmailProcessing();
        if (Yii::$app->request->isGet) {
            $model->scenario = CsvImport::SCENARIO_FIELDS_MAP;
            if ($model->validate()) {
                return $this->renderUniversal('/userEmail/data-for-generate', ['model' => $model]);
            }
        } else {
            $params = Yii::$app->request->post();
            if (empty($params['UserEmailProcessing']['fields'])) {
                $model->scenario = CsvImport::SCENARIO_FIELDS_MAP;
                $model->load($params);
                if ($model->validate()) {
                    $file = new File();
                    $file->uploadFile = UploadedFile::getInstance($model, 'uploadFile');
                    $file->path = '/file-for-gmail-user-email';
                    $file->save();
                    $model->file_id = $file->primaryKey;
                    $model->file_path = $file->getOriginalPath();
                    $data = $model->getGridData();

                    return $this->renderUniversal('/userEmail/attributes-assign', [
                        'model'  => $model,
                        'header' => $data['header'],
                        'data'   => $data['data'],
                    ]);
                }
            } else {
                $model->scenario = CsvImport::SCENARIO_DEFAULT;
                if ($model->load($params)) {
                    if ($model->validate()) {
                        $model->file_path = $model->file->getOriginalPath();
                        $result = $model->process();
                        Yii::$app->session->setFlash($result['status'], $result['text']);

                        return $this->renderUniversal('/userEmail/result', [
                            'model' => $model,
                        ]);
                    }
                }
            }
        }
        throw new BadRequestHttpException(implode('. ', $model->getFirstErrors()));
    }
}