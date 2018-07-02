<?php

namespace app\modules\gmail\models\register;

use app\models\document\Email;
use app\models\register\Register;
use app\validators\UuidValidator;

/**
 * Письма на отправку
 *
 * @property string $email_id              email
 */
class SendPool extends Register
{
    /**
     * @inheritdoc
     */
    public function rules()
    {
        return array_merge(
            parent::rules(),
            [
                [['email_id'], 'required'],
                [['email_id'], 'unique'],
                [['email_id'], UuidValidator::class],
            ]
        );
    }

    /**
     * @inheritdoc
     */
    public static function tableNamePrefix()
    {
        return 'gmail_' . parent::tableNamePrefix();
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getEmail()
    {
        return $this->hasOne(Email::class, ['id' => 'email_id']);
    }

    /**
     * @param string $emailId
     *
     * @throws \yii\base\Exception
     * @throws \yii\base\UserException
     */
    public static function register($emailId)
    {
        if (!self::find()->andWhere(['email_id' => $emailId])->exists()) {
            $model = new self();
            $model->email_id = $emailId;
            $model->save();
        }
    }
}