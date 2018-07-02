<?php

namespace app\modules\gmail;

use Yii;
use yii\base\InvalidParamException;
use yii\console\Application;

/**
 * Модуль загрузки почты с сервиса Gmail
 */
class Module extends \yii\base\Module
{
    /**
     * @inheritdoc
     */
    public $controllerNamespace = 'app\modules\gmail\controllers';

    /**
     * @var string название сертификата для подключения к почте
     */
    public $projectCertificateName;

    /**
     * @var int количество одновременных загрузок
     */
    public $quantitySynchronousDownloads = 5;

    /** @var string ключ redis для перечесления настроек для загрузки */
    const KEY_FOR_EXCHANGE_SETUP_ID = 'gmail:exchange_setup_id:';

    /** @var string  ключ redis для перечесления уже занятых настроек для загрузки */
    const KEY_FOR_BUSY_EXCHANGE_SETUP_ID = 'gmail:busy_exchange_setup_id:';

    /** @var string ключ redis для уже загруженных писем */
    const KEY_PREFIX_FOR_DOWNLOAD_MESSAGES = 'emailManager:download_messages:';

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
        if (Yii::$app instanceof Application) {
            $this->controllerNamespace = 'app\modules\gmail\commands';
        }
        if (!$this->projectCertificateName) {
            throw new InvalidParamException('Для работы модуля необходимо указать сертификат');
        }
    }
}
