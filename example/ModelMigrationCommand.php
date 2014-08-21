<?php
// У нас обязательно должен быть namespace
namespace some\example\name\space;

// Принудительно подключаем нашу библиотеку
\Yii::import('ext.yii-model-migratre.ModelMigrationCommand', true);


/**
 * Class ModelMigrationCommand
 * Свое казино с блекджеком!
 *
 * @package some\example\name\space
 */
class ModelMigrationCommand extends \yiiModelMigrate\ModelMigrationCommand
{
}
