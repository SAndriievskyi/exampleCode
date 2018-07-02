<?php

namespace app\modules\gmail\migrations;

use app\components\Migration;

class m180425_083734_send_pool extends Migration
{
    public function safeUp()
    {
        $this->createRegisterTable('{{gmail_reg_send_pool}}', [
            'email_id' => $this->uuid()->notNull()->foreignKey('{{doc_email}}', 'id')->unique(),
        ]);
    }

    public function safeDown()
    {
        $this->dropTable('{{gmail_reg_send_pool}}');
    }
}
