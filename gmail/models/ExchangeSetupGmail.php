<?php

namespace app\modules\gmail\models;

use app\components\DateTime;
use app\models\crossTable\UserEmailExchangeSetup;
use app\models\enum\Entity;
use app\models\reference\ExchangeSetup;
use app\models\enum\ExchangeSetupType;
use app\models\reference\TaskPoolSetting;
use app\models\reference\User;
use app\validators\EnumValidator;
use app\validators\UuidValidator;

/**
 * Настройки обмена с сервисом Gmail
 *
 * @property User $user               пользователь
 *
 * @package app\modules\gmail\models
 */
class ExchangeSetupGmail extends ExchangeSetup
{
    /**
     * @var DateTime время последней загрузки
     */
    public $last_download_date;

    /**
     * @var  bool создавать задачи
     */
    public $is_create_task;

    /**
     * @var  bool смотрящий
     */
    public $from_observer;

    /**
     * @var  string ошибка подключения к ящику
     */
    public $connection_error;

    /**
     * @var  DateTime время ошибка подключения к ящику
     */
    public $connection_error_date_time;

    /**
     * @var integer Тип задачи
     */
    public $task_type_id;

    /**
     * @var string Идентификатор настройки пула B2B
     */
    public $taskPoolSettingB2BId;

    /**
     * @var bool только для отправки писем
     */
    public $is_send_only;

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return array_merge(
            parent::rules(),
            [
                [['exchange_setup_type_id'], 'default', 'value' => ExchangeSetupType::GMAIL],
                [['connection_error'], 'string'],
                [
                    ['taskPoolSettingB2BId'],
                    'required',
                    'when' => function () {
                        return $this->is_create_task;
                    },
                ],
                [['is_create_task', 'from_observer', 'is_send_only'], 'default', 'value' => false],
                [['is_create_task', 'from_observer', 'is_send_only'], 'boolean'],
                [['name'], 'unique'],
                [
                    ['task_type_id'],
                    'in',
                    'range' => [
                        Entity::TASK_EMAIL_ORDER,
                        Entity::TASK_EMAIL_ORDER_CLAIM,
                        Entity::TASK_EMAIL_BOOKKEEPING,
                        Entity::TASK_FROM_EMAIL_FRANCHISE,
                    ],
                ],
                [['taskPoolSettingB2BId',], UuidValidator::className()],
                [
                    ['taskPoolSettingB2BId',],
                    'exist',
                    'skipOnError'     => true,
                    'targetClass'     => TaskPoolSetting::className(),
                    'targetAttribute' => 'id',
                ],
                [
                    ['last_download_date', 'connection_error_date_time'],
                    'date',
                    'format' => 'php:' . DateTime::DB_DATETIME_FORMAT,
                ],
                [
                    ['exchange_setup_type_id'],
                    EnumValidator::className(),
                    'targetClass' => ExchangeSetupType::className(),
                ],
                [['task_type_id'], 'default', 'value' => Entity::TASK_EMAIL_ORDER],
            ]
        );
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'last_download_date'         => 'Дата последней загрузки',
            'connection_error_date_time' => 'Дата последней ошибки подключения',
            'is_create_task'             => 'Создавать задачу',
            'connection_error'           => 'Ошибка подключения',
            'from_observer'              => 'Письма от наблюдателей за задачами',
            'task_type_id'               => 'Тип задачи',
            'taskPoolSettingB2BId'       => 'Идентификатор настройки пула B2B',
            'is_send_only'               => 'Только для отправки писем',
        ];
    }

    /**
     * @inheritdoc
     */
    protected function getPackedParams()
    {
        return [
            'last_download_date',
            'connection_error',
            'is_create_task',
            'from_observer',
            'connection_error_date_time',
            'task_type_id',
            'taskPoolSettingB2BId',
            'is_send_only',
        ];
    }

    /**
     * @inheritdoc
     */
    public static function getSingularNominativeName()
    {
        return 'Настройка обмена с сайтом';
    }

    /**
     * @inheritdoc
     */
    public static function getPluralNominativeName()
    {
        return 'Настройки обмена с сайтом';
    }

    /**
     * @inheritdoc
     */
    public function getFieldsOptions()
    {
        if ($this->_fieldsOptions === []) {
            parent::getFieldsOptions();
            $this->_fieldsOptions['last_download_date'] = ['type' => 'datetime'];
            $this->_fieldsOptions['connection_error_date_time'] = ['type' => 'datetime'];
            $this->_fieldsOptions['is_create_task'] = ['type' => 'boolean'];
            $this->_fieldsOptions['from_observer'] = ['type' => 'boolean'];
            $this->_fieldsOptions['is_send_only'] = ['type' => 'boolean'];
            $this->_fieldsOptions['task_type_id']['inputOptions'] = [
                'items' => [
                    Entity::TASK_EMAIL_ORDER       => Entity::getNameById(Entity::TASK_EMAIL_ORDER),
                    Entity::TASK_EMAIL_ORDER_CLAIM => Entity::getNameById(Entity::TASK_EMAIL_ORDER_CLAIM),
                    Entity::TASK_EMAIL_BOOKKEEPING => Entity::getNameById(Entity::TASK_EMAIL_BOOKKEEPING),
                    Entity::TASK_FROM_EMAIL_FRANCHISE => Entity::getNameById(Entity::TASK_FROM_EMAIL_FRANCHISE),
                ],
            ];
        }

        return $this->_fieldsOptions;
    }

    /**
     * Возвращает запрос для получения типа задачи, которую надо создавать при поулчении письма
     * @return \yii\db\ActiveQuery
     */
    public function getTaskType()
    {
        return $this->hasOne(Entity::class, ['id' => 'task_type_id']);
    }

    /**
     * Возвращает запрос для получения модели связи настройки с пользователем
     *
     * @return \yii\db\ActiveQuery
     */
    public function getUserEmailExchangeSetup()
    {
        return $this->hasOne(UserEmailExchangeSetup::className(), ['exchange_setup_id' => 'id']);
    }

    /**
     * Возвращает запрос для получения модели пользователя
     *
     * @return \yii\db\ActiveQuery
     */
    public function getUser()
    {
        return $this->hasOne(User::className(), ['id' => 'user_id'])->via('userEmailExchangeSetup');
    }
}