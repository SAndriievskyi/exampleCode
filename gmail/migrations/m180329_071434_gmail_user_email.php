<?php

namespace app\modules\gmail\migrations;

use app\components\Migration;
use Yii;

class m180329_071434_gmail_user_email extends Migration
{
    public function safeUp()
    {
        $this->createReferenceTable('gmail_ref_user_email', [
            'email' => $this->string(255)->notNull()->indexed(),
        ]);
        $auth = Yii::$app->authManager;
        $permission = $auth->createPermission('common.gmail.userEmail.view');
        $permission->description = 'Общие.Gmail.Почты пользователей: просмотр';
        $auth->add($permission);
        $permission = $auth->createPermission('common.gmail.userEmail.update');
        $permission->description = 'Общие.Gmail.Почты пользователей: редактирование';
        $auth->add($permission);

        $this->insertEnum('{{%enum_entity}}', [
            'id'         => 252,
            'name'       => 'Почта пользователей',
            'class_name' => 'app\modules\gmail\models\reference\UserEmail',
        ]);

        $interfaceId = (new yii\db\Query())->from('{{ref_site_interface}}')->select('id')->andWhere(['name' => 'Default'])->scalar();
        $report = (new yii\db\Query())->select('uuid_generate_v4()')->scalar();

        $this->insert('{{ref_main_menu_item}}', [
            'id'    => $report,
            'name'  => 'Почта сотрудников',
            'url'   => '/gmail/user-email/index',
            'icon'  => 'glyphicon glyphicon-piggy-bank',
            'title' => 'Почта сотрудников"',
        ]);

        $this->insert('{{cros_site_interface_main_menu_item}}', [
            'name'              => 'Почта сотрудников',
            'url'               => '/gmail/user-email/index',
            'icon'              => 'glyphicon glyphicon-piggy-bank',
            'title'             => 'Почта сотрудников',
            'site_interface_id' => $interfaceId,
            'main_menu_item_id' => $report,
            'position'          => 20,
        ]);
        Yii::$app->cache->delete('#' . Yii::$app->db->schema->getRawTableName('{{ref_site_interface}}') . '#');
    }

    public function safeDown()
    {
        $this->dropTable('gmail_ref_user_email');
        $auth = Yii::$app->authManager;
        $permission = $auth->getPermission('common.gmail.userEmail.view');
        $auth->remove($permission);
        $permission = $auth->getPermission('common.gmail.userEmail.update');
        $auth->remove($permission);

        $this->deleteEnum('{{%enum_entity}}', [
            'id' => 252,
        ]);
    }
}
