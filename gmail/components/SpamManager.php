<?php

namespace app\modules\gmail\components;

use app\components\DateTime;
use app\modules\gmail\models\ExchangeSetupGmail;
use Google_Service_Gmail;
use app\components\import\EntityManager;
use Google_Service_Gmail_Message;
use Google_Service_Gmail_ModifyMessageRequest;
use Yii;
use yii\helpers\Html;

/**
 * Компонент для поиска писем
 *
 * @property ExchangeSetupGmail $exchangeSetup экземпляр настройки обмена
 * @property Manager            $owner         родительский комопнент управления
 *
 * @package app\modules\vseinstrumeni\components
 */
class SpamManager extends EntityManager
{
    /** @var string Внешний тип сущности */
    public $remoteType = 'emailMessage';

    /** @var Google_Service_Gmail сервис */
    public $service;

    /**
     * Возвращает массив идентификаторов писем для скачки
     *
     * @return array
     */
    public function getMessageList()
    {
        $ids = [];
        $result = [];
        $params = [
            'includeSpamTrash' => true,
            'maxResults'       => 50,
            'labelIds'         => ['SPAM'],
        ];
        $list = $this->service->users_messages->listUsersMessages('me', $params);
        while ($messages = $list->getMessages()) {
            foreach ($messages as $message) {
                $ids[$message->id] = $message->id;
            }
            if ($pageToken = $list->getNextPageToken()) {
                $params['pageToken'] = $pageToken;
                $list = $this->service->users_messages->listUsersMessages('me', $params);
            } else {
                break;
            }
        }

        foreach ($ids as $id) {
            $message = $this->service->users_messages->get('me', $id, [
                'format'          => 'metadata',
                'metadataHeaders' => ['from', 'to', 'subject'],
            ]);

            $result[$id] = $this->normalizeData($message);
        }

        return $result;
    }

    /**
     * Нормализация данных для вывода
     *
     * @param Google_Service_Gmail_Message $message
     *
     * @return array
     */
    protected function normalizeData(Google_Service_Gmail_Message $message)
    {
        $from = '';
        $to = '';
        $subject = '';
        foreach ($message->getPayload()->getHeaders() as $item) {
            if (isset($item['name'], $item['value'])) {
                switch (strtolower($item['name'])) {
                    case 'from':
                        {
                            $from = $item['value'];
                            break;
                        }
                    case 'to':
                        {
                            $to = $item['value'];
                            break;
                        }
                    case 'subject':
                        {
                            $subject = Html::encode($item['value']);
                            break;
                        }
                }
            }
        }

        return [
            'id'      => $message->id,
            'from'    => $from,
            'to'      => $to,
            'subject' => $subject,
            'body'    => Html::encode($message->getSnippet()),
        ];
    }

    /**
     * Импорт изменившихся объектов
     *
     * @param array $messageIds
     *
     * @throws \yii\db\Exception
     * @throws null
     */
    public function import($messageIds = [])
    {
        foreach ($messageIds as $remoteId) {
            if (!$this->owner->getEmail()->checkMessageId($remoteId, new DateTime())) {
                $this->removeFromSpam($remoteId);
                continue;
            }
            $transaction = Yii::$app->db->beginTransaction();
            try {
                $this->removeFromSpam($remoteId);
                $this->owner->getEmail()->importObject($remoteId);
                $this->owner->getEmail()->confirmImport($remoteId, new DateTime());
                $transaction->commit();
            } catch (\Exception $e) {
                $transaction->rollBack();
                if ($this->owner->getEmail()->checkError($e)) {
                    $this->owner->registerImportError($this->remoteType, $remoteId, $e);
                } else {
                    $this->owner->clearRegisters($this->remoteType, $remoteId);
                }
            }
        }

        if ($this->exchangeSetup->user) {
            Yii::$app->ws->publish($this->exchangeSetup->user->getPersonalWsChannelName(),
                ['event' => 'emails:update', 'data' => true]);
            Yii::$app->ws->publish($this->exchangeSetup->user->getPersonalWsChannelName(),
                ['event' => 'emails:update-corporate', 'data' => true]);
        }
    }

    /**
     * Пометить как НЕ спам
     *
     * @param string $remoteId
     */
    private function removeFromSpam($remoteId)
    {
        $mods = new Google_Service_Gmail_ModifyMessageRequest();
        $mods->setRemoveLabelIds(['SPAM']);
        $this->service->users_messages->modify('me', $remoteId, $mods);
    }

    /**
     * Пометить как спам
     *
     * @param string $remoteId
     */
    public function addToSpam($remoteId)
    {
        $mods = new Google_Service_Gmail_ModifyMessageRequest();
        $mods->setAddLabelIds(['SPAM']);
        $this->service->users_messages->modify('me', $remoteId, $mods);
    }
}