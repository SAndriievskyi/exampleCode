<?php

namespace app\modules\gmail\models\processing;

use app\models\processing\CsvImport;
use app\models\reference\File;
use app\modules\gmail\models\reference\UserEmail;
use app\modules\wtis\events\UpdateUserEvent;
use app\validators\UuidValidator;
use Yii;
use yii\base\UserException;
use yii\validators\EmailValidator;
use yii\web\UploadedFile;

/**
 * Модель обработки файла с данными для почт менеджеров
 *
 * @property File $file
 */
class UserEmailProcessing extends CsvImport
{
    /**
     * @var UploadedFile $uploadFile загружаемый файл
     */
    public $uploadFile;

    /**
     * @var string $file_id идентификатор загруженного файла
     */
    public $file_id;

    /**
     * @inheritdoc
     */
    public function getAllowedFields()
    {
        return [
            'name'  => 'Имя',
            'email' => 'Почта',
        ];
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return array_merge(
            parent::rules(),
            [
                [['file_id'], UuidValidator::className()],
                [['uploadFile'], 'file', 'extensions' => "csv", 'checkExtensionByMimeType' => false],
            ]
        );
    }

    /**
     * @inheritdoc
     */
    public function getFieldsOptions()
    {
        if ($this->_fieldsOptions === []) {
            parent::getFieldsOptions();
            $this->_fieldsOptions['uploadFile'] = ['type' => 'file'];
            $this->_fieldsOptions['file_id'] = ['type' => 'hidden'];
        }

        return $this->_fieldsOptions;
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return array_merge(
            parent::attributeLabels(),
            [
                'uploadFile' => 'Загрузить файл',
            ]
        );
    }

    /**
     * Возвращает запрос на получение модели файла
     *
     * @return \yii\db\ActiveQuery
     */
    public function getFile()
    {
        return $this->hasOne(File::className(), ['id' => 'file_id']);
    }

    /**
     * Проставление кураторов у корпоративных клиентов
     *
     * @return array
     * @throws \Exception
     * @throws \yii\db\Exception
     * @throws null
     */
    public function process()
    {
        $fd = fopen($this->file_path, 'r');
        fgetcsv($fd, null, ";");
        $row = 1;
        $success = 0;
        $errors = 0;
        $issues = '';
        $validator = new EmailValidator(['enableIDN' => true]);
        while (($fileData = fgetcsv($fd, null, ";")) !== false) {
            $transaction = Yii::$app->db->beginTransaction();
            try {
                ++$row;
                $data = [];
                foreach ($this->fields as $fieldIndex => $field) {
                    $value = trim($this->convert($fileData[$fieldIndex]));
                    $data[$field] = $value;
                }
                if (empty($data['name'])) {
                    ++$errors;
                    $issues .= "Неверное имя строка {$row}<br />";
                    $transaction->commit();
                    continue;
                }
                if (empty($data['email']) || !$validator->validate($data['email'])) {
                    ++$errors;
                    $issues .= "Неверный email строка {$row}<br />";
                    $transaction->commit();
                    continue;
                }
                if (!$model = UserEmail::findOne(['email' => $data['email']])) {
                    $model = new UserEmail();
                }
                $model->email = $data['email'];
                $model->name = $data['name'];
                $model->save();
                ++$success;
                $transaction->commit();
            } catch (UserException $e) {
                $transaction->rollBack();
                ++$errors;
                $issues .= $e->getMessage() . "<br />";
            } catch (\Exception $e) {
                $transaction->rollBack();
                throw $e;
            }
        }
        $row--;
        $result = [
            'status' => 'success',
            'text'   => "Обработано строк: {$row}<br />Успешно: {$success}<br />Ошибок: {$errors}<br />" . ($issues ? "Замечания:<br />" . $issues : ''),
        ];
        fclose($fd);

        return $result;
    }

    /**
     * Обработка ивента
     *
     * @param UpdateUserEvent $event
     *
     * @throws UserException
     * @throws \yii\base\Exception
     * @throws null
     */
    public function processEvent(UpdateUserEvent $event)
    {
        if (!$model = UserEmail::findOne(['email' => (string)$event->wtisData->email])) {
            $model = new UserEmail();
        }
        $model->email = (string)$event->wtisData->email;
        $model->name = (string)$event->user;
        if ($model->validate()) {
            $model->save();
        }
    }

    /**
     * @inheritdoc
     */
    public static function getSingularNominativeName()
    {
        return 'Загрузка почт пользователей из файла';
    }
}