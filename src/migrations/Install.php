<?php

namespace paxxion\craftredirector\migrations;

use Craft;
use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $notFound = '{{%redirector_not_found}}';
        if (Craft::$app->db->schema->getTableSchema($notFound) === null) {
            $this->createTable($notFound, [
                'id' => $this->primaryKey(),
                'uid' => $this->uid(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'siteId' => $this->integer()->notNull(),
                'url' => $this->string(512)->notNull(),
                'lastReferrer' => $this->string(512),
                'totalHits' => $this->integer()->notNull()->defaultValue(0),
                'dateLastHit' => $this->dateTime()->notNull(),
                'handled' => $this->boolean()->notNull(),
            ]);

            $this->createIndex(null, $notFound, ['siteId', 'url'], false);

            $this->addForeignKey(null, $notFound, 'siteId', '{{%sites}}', 'id', 'CASCADE', 'CASCADE');
        }

        $redirects = '{{%redirector_redirects}}';
        if (Craft::$app->db->schema->getTableSchema($redirects) === null) {
            $this->createTable($redirects, [
                'id' => $this->primaryKey(),
                'uid' => $this->uid(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'enabled' => $this->boolean()->notNull(),
                'siteId' => $this->integer(),
                'entryId' => $this->integer(),
                'oldUrl' => $this->string(512)->notNull(),
                'newUrl' => $this->string(512),
                'matchType' => $this->string(32)->notNull(),
                'priority' => $this->integer(),
                'httpCode' => $this->integer()->notNull(),
                'totalHits' => $this->integer()->notNull()->defaultValue(0),
                'dateLastHit' => $this->dateTime(),
            ]);

            $this->createIndex(null, $redirects, ['uid'], false);
            $this->createIndex(null, $redirects, ['siteId', 'oldUrl'], false);
            $this->createIndex(null, $redirects, ['siteId', 'entryId', 'oldUrl'], false);
            $this->createIndex(null, $redirects, ['siteId', 'matchType', 'oldUrl'], false);

            $this->addForeignKey(null, $redirects, 'siteId', '{{%sites}}', 'id', 'CASCADE', 'CASCADE');
            $this->addForeignKey(null, $redirects, 'entryId', '{{%entries}}', 'id', 'CASCADE', 'CASCADE');
        }

        $stash = '{{%redirector_stash}}';
        if (Craft::$app->db->schema->getTableSchema($stash) === null) {
            $this->createTable($stash, [
                'id' => $this->primaryKey(),
                'uid' => $this->uid(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'siteId' => $this->integer()->notNull(),
                'entryId' => $this->integer()->notNull(),
                'entryOldUrl' => $this->string(512)->notNull(),
            ]);

            $this->createIndex(null, $stash, ['siteId', 'entryId'], false);

            $this->addForeignKey(null, $stash, 'siteId', '{{%sites}}', 'id', 'CASCADE', 'CASCADE');
            $this->addForeignKey(null, $stash, 'entryId', '{{%entries}}', 'id', 'CASCADE', 'CASCADE');
        }

        $history = '{{%redirector_history}}';
        if (Craft::$app->db->schema->getTableSchema($history) === null) {
            $this->createTable($history, [
                'id' => $this->primaryKey(),
                'uid' => $this->uid(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'siteId' => $this->integer()->notNull(),
                'entryId' => $this->integer()->notNull(),
                'entryUrl' => $this->string(512)->notNull()
            ]);

            $this->createIndex(null, $history, ['siteId', 'entryId'], false);

            $this->addForeignKey(null, $history, 'siteId', '{{%sites}}', 'id', 'CASCADE', 'CASCADE');
            $this->addForeignKey(null, $history, 'entryId', '{{%entries}}', 'id', 'CASCADE', 'CASCADE');
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%redirector_not_found}}');
        $this->dropTableIfExists('{{%redirector_redirects}}');
        $this->dropTableIfExists('{{%redirector_stash}}');
        $this->dropTableIfExists('{{%redirector_history}}');

        return true;
    }
}
