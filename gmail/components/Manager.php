<?php

namespace app\modules\gmail\components;

use app\components\exceptions\IntegrationException;
use app\models\document\Email;
use app\models\enum\Entity;
use app\models\reference\ExchangeSetup;
use app\models\register\ExchangeRemoteId;
use app\modules\gmail\Module;
use Google_Client;
use Google_Service_Gmail;
use yii\db\Exception;

/**
 * Менеджер загрузок объектов с Gmail
 */
class Manager extends \app\components\import\Manager
{
    /** @var EmailManager компонента для работы с письмами */
    protected $email;

    /**
     * Создание сервиса
     *
     * @param array $scopes
     *
     * @return Google_Service_Gmail
     */
    private function getService(array $scopes)
    {
        /** @var Module $module */
        $module = Module::getInstance() ?: \Yii::$app->getModule('gmail');
        $client = new Google_Client();
        foreach ($scopes as $scope) {
            $client->setScopes($scope);
        }
        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . \Yii::getAlias($module->projectCertificateName));
        $client->useApplicationDefaultCredentials();
        $client->setSubject($this->exchangeSetup->name); //important

        return new Google_Service_Gmail($client);
    }

    /**
     * Возвращает компонент для работы с юридическими контрагентами
     *
     * @return EmailManager
     */
    public function getEmail()
    {
        if (!$this->email) {
            $this->email = new EmailManager([
                'exchangeSetup' => $this->exchangeSetup,
                'owner'         => $this,
                'service'       => $this->getService([Google_Service_Gmail::GMAIL_READONLY]),
            ]);
        }

        return $this->email;
    }

    /**
     * Возвращает идентификаторы найденных сообщений
     *
     * @param array|string $exchangeSetupId идентификатор настройки
     * @param string       $term            строка поиска
     *
     * @return array
     */
    public function search($exchangeSetupId, $term)
    {
        $result = [];
        foreach (ExchangeSetup::findAll($exchangeSetupId) as $exchangeSetup) {
            $this->exchangeSetup = $exchangeSetup;
            $manager = new SearchManager([
                'exchangeSetup' => $this->exchangeSetup,
                'owner'         => $this,
                'service'       => $this->getService([Google_Service_Gmail::GMAIL_READONLY]),
                'term'          => $term,
            ]);
            $result = array_merge($result, $manager->import());
        }

        return $result;
    }

    /**
     * Отправка сообщений
     *
     * @param Email        $email
     *
     * @throws \yii\base\Exception
     */
    public function send(Email $email)
    {
        $this->exchangeSetup = ExchangeSetup::findOne(['name' => $email->email_sender]);
        if (!$this->exchangeSetup) {
            throw new IntegrationException('Нет настройки обмена с Gmail');
        }
        $manager = new SendManager([
            'exchangeSetup' => $this->exchangeSetup,
            'owner'         => $this,
            'service'       => $this->getService([
                Google_Service_Gmail::GMAIL_SEND,
                Google_Service_Gmail::GMAIL_READONLY,
                Google_Service_Gmail::GMAIL_MODIFY,
            ]),
            'email'         => $email,
        ]);
        $manager->import();
    }

    /**
     * Отправка сообщений
     */
    public function getSpam()
    {
        $manager = new SpamManager([
            'exchangeSetup' => $this->exchangeSetup,
            'owner'         => $this,
            'service'       => $this->getService([
                Google_Service_Gmail::GMAIL_READONLY,
            ]),
        ]);

        return $manager->getMessageList();
    }

    /**
     * Загрузка спам сообщений
     *
     * @param array $messageIds
     *
     * @throws Exception
     * @throws null
     */
    public function importSpam($messageIds)
    {
        $manager = new SpamManager([
            'exchangeSetup' => $this->exchangeSetup,
            'owner'         => $this,
            'service'       => $this->getService([
                Google_Service_Gmail::GMAIL_READONLY,
                Google_Service_Gmail::GMAIL_MODIFY,
            ]),
        ]);
        $manager->import($messageIds);
    }

    /**
     * Пометка сообщения как спам
     *
     * @param string $messageId
     *
     * @throws \yii\db\Exception
     */
    public function setSpam($messageId)
    {
        $manager = new SpamManager([
            'exchangeSetup' => $this->exchangeSetup,
            'owner'         => $this,
            'service'       => $this->getService([
                Google_Service_Gmail::GMAIL_READONLY,
                Google_Service_Gmail::GMAIL_MODIFY,
            ]),
        ]);

        if ($remoteId = ExchangeRemoteId::getRemoteId(Entity::EMAIL, $messageId, $this->exchangeSetup->primaryKey)) {
            $manager->addToSpam($remoteId);
        } else {
            throw new Exception('No link with Gmail message id');
        }
    }
}
