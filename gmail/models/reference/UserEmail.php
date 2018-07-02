<?php

namespace app\modules\gmail\models\reference;

use app\models\reference\Reference;

/**
 * Почты пользователей
 *
 * @property string $email              email
 */
class UserEmail extends Reference
{
    /**
     * @inheritdoc
     */
    public function rules()
    {
        return array_merge(
            parent::rules(),
            [
                [['email'], 'required'],
                [['email'], 'email', 'enableIDN' => true],
                [['email'], 'unique'],
            ]
        );
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return array_merge(parent::attributeLabels(), [
            'email'               => 'Email',
            'reference_status_id' => 'Статус',
            'referenceStatus'     => 'Статус',
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getFieldsOptions()
    {
        if ($this->_fieldsOptions === []) {
            parent::getFieldsOptions();
            $this->_fieldsOptions['create_user_id'] = ['isSelectAbsoluteUrl' => true, 'type' => 'reference'];
            $this->_fieldsOptions['updated_user_id'] = ['isSelectAbsoluteUrl' => true, 'type' => 'reference'];
        }

        return $this->_fieldsOptions;
    }

    /**
     * @inheritdoc
     */
    public static function getSingularNominativeName()
    {
        return 'Почта пользователя';
    }

    /**
     * @inheritdoc
     */
    public static function getPluralNominativeName()
    {
        return 'Почты пользователя';
    }

    /**
     * @inheritdoc
     */
    public static function tableNamePrefix()
    {
        return 'gmail_' . parent::tableNamePrefix();
    }

    public function __toString()
    {
        return "{$this->name} <{$this->email}>";
    }

    /**
     * Возвращает текстовое представление почты пользователя
     *
     * @param $emailRecipient
     *
     * @return string
     */
    public static function getEmailRecipient($emailRecipient)
    {
        $result = $emailRecipient;
        if ($emailRecipient) {
            /** @var static $userEmail */
            $userEmail = UserEmail::find()
                ->active()
                ->andWhere(['email' => $emailRecipient])
                ->one();
            if ($userEmail) {
                $result = (string)$userEmail;
            }
        }

        return $result;
    }
}