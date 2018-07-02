<?php

namespace app\modules\gmail\components;

use app\components\DateTime;
use app\components\Message;
use app\models\crossTable\EmailFile;
use app\models\document\Email;
use app\models\enum\Entity;
use app\modules\gmail\models\ExchangeSetupGmail;
use app\modules\gmail\Module;
use Google_Service_Gmail;
use app\components\import\EntityManager;
use Google_Service_Gmail_Message;
use Yii;
use yii\base\Exception;

/**
 * Компонент для поиска писем
 *
 * @property ExchangeSetupGmail $exchangeSetup экземпляр настройки обмена
 * @property Manager            $owner         родительский комопнент управления
 *
 * @package app\modules\vseinstrumeni\components
 */
class SendManager extends EntityManager
{
    /** @var string Внешний тип сущности */
    public $remoteType = 'emailMessage';

    /** @var Google_Service_Gmail сервис */
    public $service;

    /** @var Email $email */
    public $email;

    /**
     * Импорт идентификаоров найденных писем
     */
    public function import()
    {
        $mailer = Yii::$app->mailer;
        /** @var Message $emailMessage */
        $emailMessage = $mailer->compose()
            ->setHtmlBody($this->email->body)
            ->setFrom($this->email->email_sender)
            ->setTo($this->email->email_recipient)
            ->setSubject($this->email->subject);
        $emailMessage->setHeaders(['References' => $this->email->chain_id]);
        if ($this->email->copy) {
            $emailMessage->setCc(explode(',', str_replace(" ", "", $this->email->copy)));
        }
        if ($this->email->secret_copy) {
            $emailMessage->setBcc(explode(',', str_replace(" ", "", $this->email->secret_copy)));
        }
        $files = EmailFile::findAll(['email_id' => $this->email->primaryKey]);
        /** @var EmailFile $file */
        foreach ($files as $file) {
            $filePath = $file->file->getOriginalPath();
            $emailMessage->attach($filePath, ['fileName' => (string)$file->file]);
        }
        if (strlen($emailMessage->toString()) > Yii::$app->params['max_message_size']) {
            throw new Exception('Message size to large');
        }

        $data = base64_encode($emailMessage->toString());
        $data = str_replace(['+', '/', '='], ['-', '_', ''], $data); // url safe
        $gmailMessage = new Google_Service_Gmail_Message();
        $gmailMessage->setRaw($data);
        $transaction = Yii::$app->db->beginTransaction();
        $objSentMsg = null;
        try {
            $objSentMsg = $this->service->users_messages->send('me', $gmailMessage);
            $data = $this->service->users_messages->get('me', $objSentMsg->id, ['format' => 'full']);
            $payload = $data->getPayload();
            foreach ($payload->getHeaders() as $item) {
                if (isset($item['name'], $item['value']) && strtolower($item['name']) == 'message-id') {
                    $this->email->message_id = $item['value'];
                    break;
                }
            }
            if (!$this->email->chain_id) {
                $this->email->chain_id = $this->email->message_id;
            }
            $this->email->exchange_setup_id = $this->exchangeSetup->id;
            $this->email->save();
            $this->owner->setLocalId($this->email->primaryKey, Entity::EMAIL, $objSentMsg->id, $this->remoteType);
            $this->confirmImport($objSentMsg->id, new DateTime());
            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollBack();
            if ($objSentMsg && $objSentMsg->id) {
                if (EmailManager::checkError($e)) {
                    $this->owner->registerImportError($this->remoteType, $objSentMsg->id, $e);
                } else {
                    $this->owner->clearRegisters($this->remoteType, $objSentMsg->id);
                }
            } else {
                throw $e;
            }
        }
    }

    /**
     * Подтверждение выгрузки письма
     *
     * @param integer  $remoteId идентификатор заявки
     * @param DateTime $date     дата
     *
     * @throws \yii\db\Exception
     */
    private function confirmImport($remoteId, $date)
    {
        Yii::$app->redis->executeCommand('MULTI');
        Yii::$app->redis->executeCommand('SADD',
            [
                Module::KEY_PREFIX_FOR_DOWNLOAD_MESSAGES . $date->format('Y-m-d') . ':' . $this->exchangeSetup->id,
                $remoteId,
            ]);
        Yii::$app->redis->executeCommand('EXPIRE',
            [
                Module::KEY_PREFIX_FOR_DOWNLOAD_MESSAGES . $date->format('Y-m-d') . ':' . $this->exchangeSetup->id,
                86400,
            ]);
        Yii::$app->redis->executeCommand('EXPIRE',
            [Module::KEY_FOR_BUSY_EXCHANGE_SETUP_ID . $this->exchangeSetup->id, 300]);
        Yii::$app->redis->executeCommand('EXEC');
    }
}