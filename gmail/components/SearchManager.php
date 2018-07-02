<?php

namespace app\modules\gmail\components;

use app\models\register\ExchangeRemoteId;
use app\modules\gmail\models\ExchangeSetupGmail;
use Google_Service_Gmail;
use app\components\import\EntityManager;

/**
 * Компонент для поиска писем
 *
 * @property ExchangeSetupGmail $exchangeSetup экземпляр настройки обмена
 * @property Manager $owner родительский комопнент управления
 *
 * @package app\modules\vseinstrumeni\components
 */
class SearchManager extends EntityManager
{
    /** @var string Внешний тип сущности */
    public $remoteType = 'emailMessage';

    /** @var Google_Service_Gmail сервис */
    public $service;

    /** @var string */
    public $term;

    /**
     * Импорт идентификаоров найденных писем
     */
    public function import()
    {
        $params = [
            'maxResults' => 1000,
            'q' => $this->term,
        ];
        $list = $this->service->users_messages->listUsersMessages('me', $params);
        $ids = [];
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

        return ExchangeRemoteId::find()
            ->select('local_id')
            ->andWhere([
                'exchange_setup_id' => $this->exchangeSetup->id,
                'remote_type' => $this->remoteType,
                'remote_id' => $ids
            ])->column();
    }
}