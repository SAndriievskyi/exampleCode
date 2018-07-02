<?php

namespace app\modules\gmail\components;

use app\components\BinaryFile;
use app\helpers\TaskLeadExecutorHelper;
use app\models\crossTable\EmailFile;
use app\models\crossTable\LinkContractorVsContractor;
use app\models\crossTable\TaskObserverUser;
use app\models\crossTable\UserEmailExchangeSetup;
use app\models\document\Email;
use app\models\document\Order;
use app\models\document\Task;
use app\models\document\TaskEmailOrder;
use app\models\document\TaskEmailOrderClaim;
use app\models\enum\ContactType;
use app\models\enum\ContractorType;
use app\models\enum\DocumentStatus;
use app\models\enum\EmailFolder;
use app\models\enum\Entity;
use app\models\enum\ExchangeSetupType;
use app\models\enum\ReferenceStatus;
use app\models\reference\Contact;
use app\models\reference\Contractor;
use app\models\reference\File;
use app\models\reference\IndividualEntrepreneurContractor;
use app\models\reference\Jgroup;
use app\models\reference\JuristicContractor;
use app\models\reference\PhysicalContractor;
use app\models\reference\User;
use app\models\register\taskPool\TaskPool;
use app\modules\gmail\models\ExchangeSetupGmail;
use app\modules\gmail\Module;
use Google_Service_Exception;
use Google_Service_Gmail;
use Google_Service_Gmail_MessagePart;
use Yii;
use app\components\DateTime;
use app\components\import\EntityManager;
use yii\console\Exception;
use yii\db\Query;
use yii\helpers\Html;
use yii\helpers\HtmlPurifier;
use yii\helpers\Json;
use yii\helpers\Url;
use yii\validators\EmailValidator;

/**
 * Компонент для работы с заявками с сайта
 *
 * @property ExchangeSetupGmail $exchangeSetup экземпляр настройки обмена
 * @property Manager            $owner         родительский комопнент управления
 *
 * @package app\modules\vseinstrumeni\components
 */
class EmailManager extends EntityManager
{
    /** @var string Внешний тип сущности */
    public $remoteType = 'emailMessage';

    /** @var Google_Service_Gmail сервис */
    public $service;

    /** @var File[] прикрепленные файлы к сообщению */
    public $emailAttachments = [];

    /** @var File[] прикрепленные и встроенные файлы к сообщению */
    public $inlineAttachments = [];

    /**
     * Импорт изменившихся объектов
     *
     * @throws \yii\base\Exception
     * @throws \yii\base\UserException
     * @throws \yii\db\Exception
     * @throws null
     */
    public function import()
    {
        $this->stdout('Start Email import from: ' . $this->exchangeSetup->name . PHP_EOL);
        $importStartTime = microtime(true);
        $startDate = new DateTime('now');
        try {
            $messageList = $this->getMessageList();
            $this->exchangeSetup->connection_error = null;
            $this->exchangeSetup->connection_error_date_time = null;
        } catch (\Exception $e) {
            $this->exchangeSetup->connection_error = $e->getMessage();
            $this->exchangeSetup->connection_error_date_time = new DateTime();
            $this->exchangeSetup->save();

            return;
        }
        $total = count($messageList);
        $success = 0;
        $error = 0;
        $progress = 0;
        if ($total) {
            $this->startProgress($progress, $total);
            foreach ($messageList as $remoteId) {
                if (!$this->checkMessageId($remoteId, $startDate)) {
                    continue;
                }
                $transaction = Yii::$app->db->beginTransaction();
                try {
                    $this->importObject($remoteId);
                    $this->confirmImport($remoteId, $startDate);
                    $transaction->commit();
                    $success++;
                } catch (\Exception $e) {
                    $transaction->rollBack();
                    if (static::checkError($e)) {
                        $this->owner->registerImportError($this->remoteType, $remoteId, $e);
                        $error++;
                    } else {
                        $this->owner->clearRegisters($this->remoteType, $remoteId);
                    }
                }
                $progress++;
                $this->updateProgress($progress, $total);
            }
        }
        $this->exchangeSetup->last_download_date = $startDate;
        $this->exchangeSetup->save();
        if ($this->exchangeSetup->user) {
            Yii::$app->ws->publish($this->exchangeSetup->user->getPersonalWsChannelName(),
                ['event' => 'emails:update', 'data' => true]);
            Yii::$app->ws->publish($this->exchangeSetup->user->getPersonalWsChannelName(),
                ['event' => 'emails:update-corporate', 'data' => true]);
        }
        $this->endProgress();
        $importTotalTime = microtime(true) - $importStartTime;
        $this->stdout('End email import. Complete in ' . $importTotalTime . ' s. Successfully imported ' . $success . '. Errors on import ' . $error . '.' . PHP_EOL);
    }

    /**
     * Проверка, какие ошибки не регистрировать
     *
     * @param \Exception $e ошибка
     *
     * @return bool
     */
    public static function checkError(\Exception $e)
    {
        if ($e instanceof Google_Service_Exception) {
            try {
                $error = Json::decode($e->getMessage());
            } catch (\Exception $e) {
                return true;
            }
            if (isset($error['error']['message'], $error['error']['code'])
                && $error['error']['message'] == "Not Found" && $error['error']['code'] == 404
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Возвращает массив идентификаторов писем для скачки
     *
     * @return array
     */
    private function getMessageList()
    {
        $errorImport = $this->owner->getErrorImportedIds($this->remoteType);
        $ids = array_combine($errorImport, $errorImport) ?: [];
        $timestamp = (new DateTime($this->exchangeSetup->last_download_date))->getTimestamp();
        $params = [
            'maxResults' => 1000,
            'q'          => 'after:' . $timestamp,
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

        return $ids;
    }

    /**
     * Проверка, скачивалось ли до этого это письмо
     *
     * @param string   $remoteId gmail идентификатор письма
     * @param DateTime $date     дата
     *
     * @return bool
     * @throws \yii\db\Exception
     */
    public function checkMessageId($remoteId, $date)
    {
        if (Yii::$app->redis->executeCommand('SISMEMBER',
            [
                Module::KEY_PREFIX_FOR_DOWNLOAD_MESSAGES . $date->format('Y-m-d') . ':' . $this->exchangeSetup->id,
                $remoteId,
            ])
        ) {
            return false;
        }
        if ($this->owner->getLocalId($this->remoteType, $remoteId)) {
            $this->owner->clearRegisters($this->remoteType, $remoteId);

            return false;
        }

        return true;
    }

    /**
     * Подтверждение выгрузки письма
     *
     * @param integer  $remoteId идентификатор заявки
     * @param DateTime $date     дата
     *
     * @throws \yii\db\Exception
     */
    public function confirmImport($remoteId, $date)
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

    /**
     * Создание объекта
     *
     * @param string $remoteId внешний идентификатор объекта
     *
     * @return Email|bool
     * @throws Exception
     * @throws \yii\base\Exception
     * @throws \yii\base\UserException
     * @throws null
     */
    public function importObject($remoteId)
    {
        $this->emailAttachments = [];
        $this->inlineAttachments = [];

        $data = $this->service->users_messages->get('me', $remoteId, ['format' => 'full']);
        /** @var Google_Service_Gmail_MessagePart $payload */
        $payload = $data->getPayload();
        $header = $payload->getHeaders();

        $model = new Email();
        $model->scenario = Email::SCENARIO_SYSTEM;
        if ($this->exchangeSetup->user) {
            $model->user_id = $this->exchangeSetup->user->primaryKey;
        }
        $model->exchange_setup_id = $this->exchangeSetup->id;
        $model->date_time = (new DateTime())->setTimestamp((int)substr($data->internalDate, 0, -3));
        if ($headerAttributes = $this->getHeaderAttributes($header)) {
            $model->load($headerAttributes, '');
            if  (!$model->email_recipient){
                $model->email_recipient = $this->exchangeSetup->name;
            }
        } else {
            $this->owner->clearRegisters($this->remoteType, $remoteId);

            return false;
        }
        if (!$model->email_folder_id) {
            if ($model->email_sender == $this->exchangeSetup->name) {
                $model->email_folder_id = EmailFolder::SENT_MAIL;
            } else {
                $model->email_folder_id = EmailFolder::INBOX;
            }
        }
        $model->body = $this->getMessageBody($payload, $remoteId);
        $model->body = HtmlPurifier::process($model->body);
        foreach ([$model->email_recipient, $model->email_sender] as $email) {
            if (strpos($email, '@vseinstrumenti.ru') !== false) {
                continue;
            }
            $contactContractors = $this->getContactContractor($email);
            if (count($contactContractors) == 1) {
                $contactContractor = reset($contactContractors);
                $model->contact_contractor_id = $contactContractor->parent_id ?: $contactContractor->primaryKey;
            }
            $contractors = $this->getContractor(array_keys($contactContractors));
            if (count($contractors) == 1) {
                $contractor = reset($contractors);
                $model->contractor_id = $contractor->parent_id ?: $contractor->primaryKey;
            }
            if ($corporateClients = $this->getCorporateClient(array_keys($contractors))) {
                if (count($corporateClients) == 1) {
                    $corporateClient = reset($corporateClients);
                    $model->corporate_client_id = $corporateClient->primaryKey;
                }
            }
        }
        $model->seen = true;
        if (is_array($data->getLabelIds()) && in_array('UNREAD', $data->getLabelIds())) {
            $model->seen = false;
        }
        $model->save();
        foreach ($this->emailAttachments as $file) {
            $emailFile = new EmailFile();
            $emailFile->email_id = $model->primaryKey;
            $emailFile->file_id = $file->primaryKey;
            $emailFile->save();
        }
        $this->owner->setLocalId($model->primaryKey, Entity::EMAIL, $remoteId, $this->remoteType);
        $this->owner->clearRegisters($this->remoteType, $remoteId);
        if ($model->email_folder_id == EmailFolder::INBOX && $this->exchangeSetup->is_create_task) {
            $this->createTaskEmailOrder($model, $headerAttributes['initial_sender']);
        }

        return $model;
    }

    /**
     * Получение данных по объектам
     *
     * @param string $remoteId     идентфикатор по которым требуется запросить данные
     * @param string $attachmentId идентфикатор прикрепленного файла
     * @param string $fileName     имя файла
     * @param bool   $isLinkToEmail
     *
     * @return File
     * @throws Exception
     * @throws \yii\base\Exception
     * @throws \yii\base\UserException
     * @throws null
     */
    private function getAttachment($remoteId, $attachmentId, $fileName, $isLinkToEmail = true)
    {
        if (isset($this->inlineAttachments[$attachmentId])) {
            return $this->inlineAttachments[$attachmentId];
        }

        $attachment = $this->service->users_messages_attachments->get('me', $remoteId, $attachmentId);
        if (!$attachment) {
            throw new Exception('Error on import attachment');
        }
        $data = strtr($attachment->getData(), ['-' => '+', '_' => '/']);
        $data = base64_decode($data);
        $file = new File();
        $file->path = 'email/' . (new DateTime('now', false));
        $file->uploadFile = BinaryFile::getInstance($data, $fileName);
        $file->name = $file->name && trim($file->name) ? mb_substr($file->name, 0, 255) : 'Unknown';
        $file->save();
        if ($isLinkToEmail) {
            $this->emailAttachments[$attachmentId] = $file;
        }
        $this->inlineAttachments[$attachmentId] = $file;

        return $file;
    }

    /**
     * Возвращает заполненные атрибуты модели связанные с заголовком письма
     *
     * @param array $header массив приходящий с gmail api
     *
     * @return array|bool
     * @throws \yii\base\Exception
     * @throws \yii\base\UserException
     * @throws null
     */
    private function getHeaderAttributes($header)
    {
        $from = 'undefined-sender@vseinstrumenti.ru';
        $to = $this->exchangeSetup->name;
        $subject = '(без темы)';
        $copy = [];
        $messageId = '';
        $emailFolder = null;
        $chainId = null;
        $initialFrom = null;
        foreach ($header as $item) {
            if (isset($item['name'], $item['value'])) {
                switch (strtolower($item['name'])) {
                    case 'from': {
                        $initialFrom = $from = $item['value'];
                        if (preg_match('/[\._\w0-9-]+@[\._\w0-9-]+/u', $item['value'], $matches)) {
                            $initialFrom = $from = reset($matches);
                        }
                        break;
                    }
                    case 'delivered-to': {
                        $to = $item['value'];
                        if (preg_match('/[\._\w0-9-]+@[\._\w0-9-]+/u', $item['value'], $matches)) {
                            $to = reset($matches);
                        }
                        break;
                    }
                    case 'to': {
                        $arr = $this->parseEmail($item['value']);
                        if (in_array($this->exchangeSetup->name, $arr)) {
                            $to = $to ?: $this->exchangeSetup->name;
                            unset($arr[$this->exchangeSetup->name]);
                        } else {
                            $to = $to ?: array_shift($arr);
                            unset($arr[$to]);
                        }
                        $copy = array_merge($copy, $arr);
                        break;
                    }
                    case 'subject': {
                        $subject = $item['value'];
                        break;
                    }
                    case 'cc': {
                        $copy = array_merge($copy, $this->parseEmail($item['value']));
                        break;
                    }
                    case 'message-id': {
                        $messageId = $item['value'];
                        break;
                    }
                    case 'references': {
                        $chainId = $this->getChainId($item['value']);
                        break;
                    }
                }
            }
        }

        if (mb_strpos($subject, '//') !== false) {
            $value = explode('//', $subject);
            if (count($value) >= 1) {
                $validator = new EmailValidator();
                $validator->enableIDN = true;
                $email = trim(array_pop($value), "<> \t\n\r\0\x0B");
                if ($validator->validate($email)) {
                    $from = $email;
                } else {
                    $value[] = $email;
                }
            }
            $subject = implode(' ', $value);
        }

        if (in_array($from, explode(',', Yii::$app->systemSettings->email_settings->stop_list_vseinstrumenti_emails))) {
            return false;
        }
        $corporateDomains = Yii::$app->systemSettings->email_settings->corporate_domains;
        if (!$this->exchangeSetup->is_create_task && $corporateDomains) {
            $corporateDomains = explode(',', str_replace(' ', '', $corporateDomains));
            foreach ($corporateDomains as $domain) {
                if (strpos($to, $domain) !== false && strpos($from, $domain) !== false) {
                    $emailFolder = EmailFolder::CORPORATE;
                    break;
                }
            }
        }

        return [
            'email_sender'    => mb_strtolower($from),
            'initial_sender'  => mb_strtolower($initialFrom),
            'email_recipient' => mb_strtolower($to),
            'subject'         => $subject,
            'copy'            => implode(',', $copy),
            'message_id'      => $messageId,
            'email_folder_id' => $emailFolder,
            'chain_id'        => $chainId ?: $messageId,
        ];
    }

    /**
     * Выбирает из строки все значения емаилов
     *
     * @param string $value строка
     *
     * @return array
     */
    private function parseEmail($value)
    {
        $result = [];
        $arr = explode(',', str_replace(' ', '', $value));
        $validator = new EmailValidator();
        $validator->enableIDN = true;
        foreach ($arr as $item) {
            $item = preg_split("/[<>]+/", $item);
            $item = isset($item[1]) ? $item[1] : $item[0];
            $item = trim($item, "<> \t\n\r\0\x0B");
            if ($validator->validate($item)) {
                $result[$item] = $item;
            }
        }

        return $result;
    }

    /**
     * Возвращает идентификатор цепочки
     *
     * @param string $value строка с идентификаторами зависимых писем
     *
     * @return string|null
     * @throws \yii\base\Exception
     * @throws \yii\base\UserException
     * @throws null
     */
    private function getChainId($value)
    {
        if (!$emails = Email::findAll(['message_id' => explode(' ', $value)])) {
            return null;
        }
        $mainEmail = array_shift($emails);
        foreach ($emails as $email) {
            if ($email->chain_id != $mainEmail->chain_id) {
                $email->chain_id = $mainEmail->chain_id;
                $email->scenario = Email::SCENARIO_SYSTEM;
                $email->save();
            }
        }

        return $mainEmail->chain_id;
    }

    /**
     * Загрузка тела письма
     *
     * @param Google_Service_Gmail_MessagePart $payload
     * @param                                  $remoteId
     *
     * @return string
     * @throws Exception
     * @throws \yii\base\Exception
     * @throws \yii\base\UserException
     * @throws null
     */
    private function getMessageBody(Google_Service_Gmail_MessagePart $payload, $remoteId)
    {
        $parts = $payload->getParts();
        $FOUND_BODY = '';
        foreach ($parts as $part) {
            if ($part['parts']) {
                foreach ($part['parts'] as $p) {
                    if ($p['parts'] && count($p['parts']) > 0) {
                        foreach ($p['parts'] as $y) {
                            if (($y['mimeType'] === 'text/html') && $y['body']) {
                                $FOUND_BODY = $this->decodeBody($y['body']->data);
                                break;
                            }
                        }
                    } else {
                        if (($p['mimeType'] === 'text/html') && $p['body']) {
                            $FOUND_BODY = $this->decodeBody($p['body']->data);
                            break;
                        }
                    }
                }
            } elseif ($part['mimeType'] === 'text/html' && $part['body']) {
                $FOUND_BODY = $this->decodeBody($part['body']->data);
            } elseif ($part['filename'] && $part['body']->attachmentId) {
                $this->getAttachment($remoteId, $part['body']->attachmentId, $part['filename']);
            } elseif ($part['mimeType'] === 'text/plain' && $part['body']) {
                $FOUND_BODY = $this->decodeBody($part['body']->data);
            }
        }

        // With no attachment, the payload might be directly in the body, encoded.
        $body = $payload->getBody();
        // If we didn't find a body, let's look for the parts
        if (!$FOUND_BODY) {
            foreach ($parts as $part) {
                if ($part['parts'] && !$FOUND_BODY) {
                    foreach ($part['parts'] as $p) {
                        if ($p['parts'] && count($p['parts']) > 0) {
                            foreach ($p['parts'] as $y) {
                                if (($y['mimeType'] === 'text/html') && $y['body']) {
                                    $FOUND_BODY = $this->decodeBody($y['body']->data);
                                    break;
                                }
                            }
                        } else {
                            if (($p['mimeType'] === 'text/html') && $p['body']) {
                                $FOUND_BODY = $this->decodeBody($p['body']->data);
                                break;
                            }
                        }
                    }
                }
                if ($FOUND_BODY) {
                    break;
                }
            }
        }
        // let's save all the images linked to the mail's body:
        if ($FOUND_BODY && count($parts) > 1) {
            $images_linked = [];
            foreach ($parts as $part) {
                if ($part['filename']) {
                    array_push($images_linked, $part);
                } else {
                    if ($part['parts']) {
                        foreach ($part['parts'] as $p) {
                            if ($p['parts'] && count($p['parts']) > 0) {
                                foreach ($p['parts'] as $y) {
                                    if (($y['mimeType'] === 'text/html') && $y['body']) {
                                        array_push($images_linked, $y);
                                    }
                                }
                            } else {
                                if (($p['mimeType'] !== 'text/html') && $p['body']) {
                                    array_push($images_linked, $p);
                                }
                            }
                        }
                    }
                }
            }
            // special case for the wdcid...
            preg_match_all('/wdcid(.*)"/Uims', $FOUND_BODY, $wdmatches);
            if (count($wdmatches)) {
                $z = 0;
                foreach ($wdmatches[0] as $match) {
                    $z++;
                    if ($z > 9) {
                        $FOUND_BODY = str_replace($match, 'image0' . $z . '@', $FOUND_BODY);
                    } else {
                        $FOUND_BODY = str_replace($match, 'image00' . $z . '@', $FOUND_BODY);
                    }
                }
            }
            preg_match_all('/src="cid:(.*)"/Uims', $FOUND_BODY, $matches);
            if (count($matches)) {
                // let's trasnform the CIDs as base64 attachements
                foreach ($matches[1] as $match) {
                    foreach ($images_linked as $img_linked) {
                        foreach ($img_linked['headers'] as $img_lnk) {
                            if (in_array(strtolower($img_lnk['name']),
                                    ['content-id', 'x-attachment-id']) && $img_linked['body']->attachmentId) {
                                if ($match === str_replace('>', '', str_replace('<', '', $img_lnk->value))
                                    || explode("@", $match)[0] === explode(".", $img_linked->filename)[0]
                                    || explode("@", $match)[0] === $img_linked->filename
                                ) {
                                    $search = "src=\"cid:$match\"";
                                    $file = $this->getAttachment($remoteId, $img_linked['body']->attachmentId,
                                        $img_linked['filename'], false);
                                    $data = Yii::$app->assetManager->publish($file->getOriginalPath());
                                    $replace = "src=\"" . Url::to($data[1]) . "\"";
                                    $FOUND_BODY = str_replace($search, $replace, $FOUND_BODY);
                                    continue 3;
                                }
                            }
                        }
                    }
                }
            }
        }
        // If we didn't find the body in the last parts,
        // let's loop for the first parts (text-html only)
        if (!$FOUND_BODY) {
            foreach ($parts as $part) {
                if ($part['body'] && $part['mimeType'] === 'text/html') {
                    $FOUND_BODY = $this->decodeBody($part['body']->data);
                    break;
                }
            }
        }
        // With no attachment, the payload might be directly in the body, encoded.
        if (!$FOUND_BODY) {
            $FOUND_BODY = $this->decodeBody($body['data']);
        }
        // Last try: if we didn't find the body in the last parts,
        // let's loop for the first parts (text-plain only)
        if (!$FOUND_BODY) {
            foreach ($parts as $part) {
                if ($part['body']) {
                    $FOUND_BODY = $this->decodeBody($part['body']->data);
                    break;
                }
            }
        }
        if (!$FOUND_BODY) {
            $FOUND_BODY = '(No message)';
        }

        return $FOUND_BODY;
    }

    /**
     * Декодирование сообщения из base64
     *
     * @param string $message текст сообщения
     *
     * @return bool|string
     */
    private function decodeBody($message)
    {
        $message = strtr($message, '-_', '+/');
        $decodedMessage = base64_decode($message);
        if (!$decodedMessage) {
            $decodedMessage = false;
        }

        return $decodedMessage;
    }

    /**
     * Получение идентификатора контактного лица
     *
     * @param string $email модель письма
     *
     * @return PhysicalContractor[]|[]
     */
    private function getContactContractor($email)
    {
        return PhysicalContractor::find()
            ->active()
            ->standard()
            ->innerJoin(['con' => Contact::tableName()], [
                'AND',
                PhysicalContractor::tableName() . '.id = con.contractor_id',
                ['con.contact_type_id' => ContactType::EMAIL],
                ['con.reference_status_id' => ReferenceStatus::ACTIVE],
                ['con.name' => $email],
            ])
            ->indexBy('id')
            ->all();
    }

    /**
     * Возвращает модель контрагента
     *
     * @param array $contactContractorIds идентификатор контактного лица
     *
     * @return JuristicContractor[]|IndividualEntrepreneurContractor[]|[]
     */
    protected function getContractor($contactContractorIds)
    {
        if (PhysicalContractor::find()
            ->leftJoin(['lcvc' => LinkContractorVsContractor::tableName()], [
                'AND',
                Contractor::tableName() . '.id = lcvc.child_contractor_id',
                ['lcvc.reference_status_id' => ReferenceStatus::ACTIVE],
            ])
            ->andWhere([Contractor::tableName() . '.id' => $contactContractorIds])
            ->andWhere('lcvc.id is null')
            ->exists()
        ) {
            return [];
        }
        $contractors = Contractor::find()
            ->active()
            ->standard()
            ->andWhere([
                Contractor::tableName() . '.contractor_type_id' => [
                    ContractorType::INDIVIDUAL_ENTREPRENEUR,
                    ContractorType::JURISTIC_PERSON,
                ],
            ])
            ->innerJoin(['lcvc' => LinkContractorVsContractor::tableName()], [
                'AND',
                Contractor::tableName() . '.id = lcvc.parent_contractor_id',
                ['lcvc.reference_status_id' => ReferenceStatus::ACTIVE],
                ['lcvc.child_contractor_id' => $contactContractorIds],
            ])
            ->indexBy('id')
            ->all();

        return $contractors;
    }

    /**
     * Возвращает идентификатор КК
     *
     * @param array $contractorIds идентификатор контрагента
     *
     * @return Jgroup[]|[]
     */
    private function getCorporateClient($contractorIds)
    {
        return Jgroup::find()
            ->from(['jg' => Jgroup::tableName()])
            ->active('jg')
            ->innerJoin(['jc' => Contractor::tableName()], [
                'AND',
                'jc.jgroup_id = jg.id',
                ['jc.id' => $contractorIds],
                ['jc.reference_status_id' => ReferenceStatus::ACTIVE],
                ['jc.contractor_type_id' => [ContractorType::INDIVIDUAL_ENTREPRENEUR, ContractorType::JURISTIC_PERSON]]
            ])
            ->all();
    }

    /**
     * Создание задачи "Заявка с почты"
     *
     * @param Email  $email         модель письма
     * @param string $emailObserver email обозревателя
     *
     * @throws Exception
     * @throws \yii\base\Exception
     * @throws \yii\base\UserException
     * @throws \yii\db\IntegrityException
     * @throws null
     */
    private function createTaskEmailOrder(Email $email, $emailObserver)
    {
        $className = TaskEmailOrder::class;
        if ($this->exchangeSetup->task_type_id) {
            $className = Entity::getClassNameById($this->exchangeSetup->task_type_id);
        }
        /** @var $task TaskEmailOrder|TaskEmailOrderClaim */
        $task = new $className;
        $task->email_id = $email->primaryKey;
        $task->email = $email->email_sender;
        $task->contractor_id = $email->contractor_id;
        $task->contact_contractor_id = $email->contact_contractor_id;
        $task->corporate_client_id = $email->corporate_client_id;

        $emailSetupSettings = [];
        foreach (Yii::$app->taskPoolSetting->getAll() as $setting) {
            if ($setting->email_exchange_setups) {
                $emailSetupSettings = array_merge($emailSetupSettings, $setting->email_exchange_setups);
            }
        }
        if (!in_array($email->exchange_setup_id, $emailSetupSettings) && $task->email) {
            $user = (new Query())
                ->select(['c.assigned_user_id'])
                ->from(['con' => Contact::tableName()])
                ->innerJoin(['cvc' => LinkContractorVsContractor::tableName()], [
                    'and',
                    'cvc.child_contractor_id = con.contractor_id',
                    ['cvc.reference_status_id' => ReferenceStatus::ACTIVE],
                ])
                ->innerJoin(['c' => Contractor::tableName()], [
                    'and',
                    'c.id = cvc.parent_contractor_id',
                    ['is not', 'c.assigned_user_id', null],
                    ['c.reference_status_id' => ReferenceStatus::ACTIVE],
                ])
                ->innerJoin(['u' => User::tableName()], [
                    'AND',
                    'u.id = c.assigned_user_id',
                    ['u.reference_status_id' => ReferenceStatus::ACTIVE],
                ])
                ->leftJoin(['o' => Order::tableName()], [
                    'and',
                    'o.contractor_id = c.id',
                    ['o.document_status_id' => DocumentStatus::PROCESSED],
                ])
                ->andWhere([
                    'con.contact_type_id'     => ContactType::EMAIL,
                    'con.name'                => $task->email,
                    'con.reference_status_id' => ReferenceStatus::ACTIVE,
                ])
                ->orderBy(['o.date' => SORT_DESC])
                ->scalar();
            $task->executor_user_id = $user ?: null;
        }
        $dateTime = new DateTime();
        $task->start_plan_date_time = $dateTime;
        if ((date('N', strtotime($dateTime)) >= 6 && strtotime($dateTime->format('H:i:s')) > strtotime('18:00:00'))
            || (date('N', strtotime($dateTime)) < 6 && strtotime($dateTime->format('H:i:s')) > strtotime('20:00:00'))
        ) {
            $task->start_plan_date_time = (new DateTime('+1 day'))->format('Y-m-d 09:00:00');
        }

        if (in_array(Entity::getIdByClassName($className), Task::leadTask)
            && !$task->executor_user_id
            && $task->contractor_id
            && $task->contractor->assigned_user_id
        ) {
            if ($data = TaskLeadExecutorHelper::getExecutorId($task->contractor->assigned_user_id,
                $task->start_plan_date_time)
            ) {
                $task->plan_executor_user_id = $task->executor_user_id = $data['user_id'];
                $task->start_plan_date_time = $data['start_plan_date'] ?: $task->start_plan_date_time;
            } else {
                $task->taskPoolId = $this->exchangeSetup->taskPoolSettingB2BId;
            }
        }
        $task->save();

        if ($this->exchangeSetup->from_observer
            && $observer = User::find()
                ->select('u.id')
                ->from(['u' => User::tableName()])
                ->innerJoin(['ues' => UserEmailExchangeSetup::tableName()], 'ues.user_id = u.id')
                ->innerJoin(['es' => ExchangeSetupGmail::tableName()], [
                    'AND',
                    'ues.exchange_setup_id = es.id',
                    ['es.exchange_setup_type_id' => ExchangeSetupType::GMAIL],
                ])
                ->andWhere([
                    'AND',
                    ['u.reference_status_id' => ReferenceStatus::ACTIVE],
                    ['es.name' => $emailObserver]
                ])
                ->limit(1)
                ->scalar()
        ) {
            $taskObserver = new TaskObserverUser();
            $taskObserver->user_id = $observer;
            $taskObserver->task_id = $task->primaryKey;
            $taskObserver->save();
        }

        if (!$task->taskPoolId && !$task->executor_user_id) {
            $taskPool = null;
            foreach (Yii::$app->taskPoolSetting->getAll() as $setting) {
                if ($setting->email_exchange_setups && in_array($email->exchange_setup_id,
                        $setting->email_exchange_setups)
                ) {
                    $taskPool = TaskPool::register($task, $setting->primaryKey);
                    break;
                }
            }
            if (!$taskPool) {
                throw new Exception('Задача не попала в пул и у нее нет исполнителя');
            }
        }
        if ($task->executor_user_id) {
            Yii::$app->mailer->compose()
                ->setTextBody('<p>' . Html::a('Ссылка на задачу в CRM',
                        Url::to(['/document/task/process', 'id' => $task->primaryKey],
                            true)) . '</p><br />' . $email->body)
                ->setSubject('Информирование CRM')
                ->setTo($task->executorUser->defaultEmailExchangeSetup->name)
                ->setFrom('crm2@vseinstrumenti.ru')
                ->send();
        }
    }
}