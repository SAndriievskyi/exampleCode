<?php

namespace app\modules\gmail\commands;

use app\commands\Controller;
use app\components\DateTime;
use app\models\enum\ExchangeSetupType;
use app\models\enum\ReferenceStatus;
use app\modules\gmail\components\Manager;
use app\modules\gmail\models\ExchangeSetupGmail;
use app\modules\gmail\models\register\SendPool;
use app\modules\gmail\Module;
use Exception;
use Yii;

/**
 * Планировщик задач для загрузки почты
 */
class ImportController extends Controller
{
    /**
     * Импорт данных
     *
     * @throws Exception
     */
    public function actionImport()
    {
        /** @var ExchangeSetupGmail[] $settings */
        $settings = ExchangeSetupGmail::find()
            ->andWhere([
                'exchange_setup_type_id' => ExchangeSetupType::GMAIL,
                'reference_status_id'    => ReferenceStatus::ACTIVE,
            ])
            ->orderBy('updated_at DESC')
            ->all();
        if ($settings) {
            foreach ($settings as $setting) {
                if ($setting->is_send_only) {
                    continue;
                }
                if (!$setting->connection_error_date_time || ($setting->connection_error_date_time < (new DateTime('-30 minutes')))) {
                    Yii::$app->redis->executeCommand('MULTI');
                    Yii::$app->redis->executeCommand('SADD', [Module::KEY_FOR_EXCHANGE_SETUP_ID, $setting->primaryKey]);
                    Yii::$app->redis->executeCommand('EXPIRE', [Module::KEY_FOR_EXCHANGE_SETUP_ID, 180]);
                    Yii::$app->redis->executeCommand('EXEC');
                }
            }
            exec('ps aux | grep -v grep | grep gmail/import/process', $result);
            $module = Module::getInstance();
            $cnt = $module->quantitySynchronousDownloads - count($result);
            for ($i = 1; $i <= $cnt; $i++) {
                exec("php /home/crm/www/yii gmail/import/process > /dev/null 2>&1 &");
            }
        }
    }

    /**
     * Проверка и загрузка с ящиков занесенных в redis
     */
    public function actionProcess()
    {
        $limits = 0;
        while ($limits <= 100 && $keys = Yii::$app->redis->executeCommand('SMEMBERS',
                [Module::KEY_FOR_EXCHANGE_SETUP_ID])) {
            foreach ($keys as $exchangeSetupId) {
                Yii::$app->redis->executeCommand('SREM', [Module::KEY_FOR_EXCHANGE_SETUP_ID, $exchangeSetupId]);
                if ($exchangeSetupId && Yii::$app->redis->executeCommand('SET', [
                        Module::KEY_FOR_BUSY_EXCHANGE_SETUP_ID . $exchangeSetupId,
                        '1',
                        'NX',
                        'EX',
                        3600,
                    ]) != 0
                ) {
                    $this->actionImportOne($exchangeSetupId);
                    Yii::$app->redis->executeCommand('DEL',
                        [Module::KEY_FOR_BUSY_EXCHANGE_SETUP_ID . $exchangeSetupId]);
                    $limits++;
                    break 1;
                }
            }
        }
    }

    /**
     * Импорт данных
     *
     * @param $exchangeSetupId
     *
     * @throws Exception
     */
    public function actionImportOne($exchangeSetupId)
    {
        Yii::$app->exchangeSetup->setId($exchangeSetupId);
        /** @var ExchangeSetupGmail $exchangeSetup */
        $exchangeSetup = Yii::$app->exchangeSetup->getIdentity();
        if (!$exchangeSetup) {
            throw new Exception('Необходимо указать настройку обмена');
        }
        $manager = new Manager(['exchangeSetup' => $exchangeSetup]);
        $manager->import(['email']);
    }

    /**
     * @throws \yii\base\Exception
     */
    public function actionSendEmail()
    {
        foreach (SendPool::find()->with('email')->each() as $pool) {
            /** @var SendPool $pool */
            $manager = new Manager();
            try {
                $manager->send($pool->email);
                $pool->delete();
            } catch (\Exception $e) {
                $pool->delete();
                throw $e;
            }
        }
    }
}