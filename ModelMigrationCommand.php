<?php
namespace yiiModelMigrate;

use Zend\Code\Reflection;

/**
 * Class ModelMigrationCommand
 * Миграции для моделей
 * @author Xakki yii@xakki.ru
 * @version 0.2
 * @package extensions\ModelMigration
 *
 * @property CDbConnection      $connectionID      по умолчанию БД
 * @property string             $templateFile      Шаблон миграции
 * @property string             $templateSpace     заполняет шаблон пробелами для разделения строк
 * @property boolean            $addComments       Добавлять описание атрибутов и таблицы в БД
 */

class ModelMigrationCommand extends \CConsoleCommand
{
    const VERSION = '0.2';

    private $_db;

    public $connectionID = 'db';
    public $templateFile = 'template.tpl';
    public $templateSpace = "\n        ";
    public $addComments = false;

    protected function beforeAction($action, $params)
    {
        $yiiVersion = \Yii::getVersion();
        echo PHP_EOL . "Yii Migration for Model Tool v" . self::VERSION . " (based on Yii v{$yiiVersion})" . PHP_EOL;
        return parent::beforeAction($action, $params);
    }

    /**
     * Миграция по модели
     * php yiic.php modelMigrate index "/namespase/path/to/model"
     * @param $args
     *
     * @return int
     */
    public function actionIndex($args)
    {
        if (!isset($args[0]) || !$args[0]) {
            echo "Error: Missing the model name." . PHP_EOL;
            return 1;
        }
        return $this->actionCreate($args[0]);
    }

    /**
     * Миграция по фаилу содержащий модель
     * php yiic.php modelMigrate byfile "file/path"
     * PhpShtorm : Settings->Remote SSH External Tools->Add [Program -> php | parameters -> yiic.php modelMigrate byfile "$FilePathRelativeToProjectRoot$" ]
     * где "yiic.php" - укажите ваш путь к запуску
     * @param $args
     *
     * @return int
     */
    public function actionByfile($args)
    {
        if (!isset($args[0]) || !$args[0] || !file_exists($args[0])) {
            $this->usageError('Error: Missing the file name or not exists.');
        }
        $content = file_get_contents($args[0]);
        $class = '';
        $matches = [];

        preg_match("/namespace (.+)\;/", $content, $matches);

        if (count($matches) > 1 and $matches[1]) {
            $class .= '\\' . $matches[1] . '\\';
        }
        preg_match("/class (\w+) extends/", $content, $matches);

        if (count($matches) > 1 and $matches[1]) {
            $class .= $matches[1];
        }

        return $this->actionCreate($class);
    }

    /**
     * Создание миграции для модели
     * @param $className string
     * @return int
     */
    public function actionCreate($className)
    {
        if (!$className) {
            $this->usageError('Please provide the name of the model.');
        }

        // 1 - find model
        $modelReflection = self::getModelObject($className);
        if (!$modelReflection) {
            return 1;
        }

        // 2 - get table dif structure
        $modelSqlInfoDocX = $this->getModelSqlInfoFromDocX($modelReflection, $modelReflection->getName());
        if (is_null($modelSqlInfoDocX)) {
            $this->usageError('The model "' . $className . '" don`t have SQL info');
        }

        // 3 - get diff
        $modelSqlDiff = $this->getModelSqlDiff($modelSqlInfoDocX);
        if (!count($modelSqlDiff)) {
            echo "Ok! The model '{$className}' is good and don't have sql change." . PHP_EOL;
            return 0;
        }

        // 4 - create sql command
        list($queryUp, $queryDown) = $this->getSqlQuery($modelSqlDiff);

        $confirm = 'Create new migration?' . PHP_EOL
            . '------ UP' . $this->templateSpace
            . implode($this->templateSpace, $queryUp) . PHP_EOL
            . '------ DOWN' . $this->templateSpace
            . implode($this->templateSpace, $queryDown) . PHP_EOL;

        if ($this->confirm($confirm)) {
            // 4 - create migration file
            \Yii::import('core.cli.commands.CMigrateCommand');
            $command = new \CMigrateCommand('migrate', $this->getCommandRunner());
            $command->interactive = false;
            $command->templateFile = $this->getTemplateFile($queryUp, $queryDown);
            $mname = 'modelMigrate_' . \Yii::app()->format->formatToCamelCase($className);
            $command->run(array('create', $mname));

            // выводим сообщение
            $mname = 'm' . gmdate('ymd_His') . '_' . $mname;
            $file = $command->migrationPath . DIRECTORY_SEPARATOR . $mname . '.php';
            // Insert our code in file
            echo PHP_EOL . "New migration model created successfully - {$file}." . PHP_EOL;
        }
        return 0;
    }

    /*****************************************************/

    /**
     * шаблон для создании миграции
     * @param $queryUp array
     * @param $queryDown array
     * @return string
     */
    private function getTemplateFile($queryUp, $queryDown)
    {
        $content = file_get_contents($this->templateFile);
        $content = strtr(
            $content,
            array(
                '{safeUp}' => $this->templateSpace . implode($this->templateSpace, $queryUp),
                '{safeDown}' => $this->templateSpace . implode($this->templateSpace, $queryDown),
            )
        );
        $file = tempnam(sys_get_temp_dir(), 'modelMigration.');
        file_put_contents($file, $content);
        return $file;
    }

    /**
     * Возвращаем обект клсасса или NULL если его нет
     * @param $name
     * @return Reflection\ClassReflection
     */
    private static function getModelObject($name)
    {
        if (!class_exists($name)) {
            echo "Error: The model `" . $name . "` cant find." . PHP_EOL;
            return null;
        }
        $reflection = new Reflection\ClassReflection($name);

        return $reflection;
    }

    /**
     * Получаем информоцию о структуре БД для модели по комментам
     *
     * @param $modelReflection Reflection\ClassReflection
     * @param $parentClass
     *
     * @return array|null
     */
    private function getModelSqlInfoFromDocX($modelReflection, $parentClass)
    {
        $docs = $modelReflection->getDocBlock();

        $sqlDoc = array(
            'connectionId' => '',
            'tables' => array(),
            'tableComment' => '',
            'columns' => array(),
            'columnsComment' => array(),
            'pkey' => '',
            'index' => array(),
        );

        // get doc from parent class
        if ($parent = $modelReflection->getParentClass()) {
            if ($parentSqlDoc = $this->getModelSqlInfoFromDocX($parent, $parentClass)) {
                $sqlDoc = $parentSqlDoc;
            }
        }

        if ($this->addComments && $docs) {
            $sqlDoc['tableComment'] = $docs->getShortDescription();
            if (!$sqlDoc['tableComment']) {
                $sqlDoc['tableComment'] = $docs->getLongDescription();
            }
        }
        $this->appendDocTableNames($sqlDoc, $docs, $parentClass);

        if (!$docs) {
            if (isset($parentSqlDoc)) {
                return $sqlDoc;
            }
            return null;
        }

        $property = $docs->getTags('property');
        foreach ($property as $r) {
            $desc = $r->getDescription();
            $matches = array();
            if (preg_match('/(.*)\[([^\]\[]*)\]/', $desc, $matches) && isset($matches[2])) {
                $key = trim($r->getPropertyName(), '$');
                $sqlDoc['columns'][$key] = $matches[2];
                if ($this->addComments) {
                    $sqlDoc['columnsComment'][$key] = $matches[1];
                }
            }
        }

        $exclude = $docs->getTags('exclude');
        foreach ($exclude as $r) {
            unset($sqlDoc['columns'][trim($r->getContent(), '$')]);
        }

        $pkey = $docs->getTag('sqlPrimary');
        if ($pkey) {
            $sqlDoc['pkey'] = $pkey->getContent();
        }

        $sql_index = $docs->getTags('sqlIndex'); //

        foreach ($sql_index as $r) {
            self::appendDocIndex($sqlDoc['index'], $r);
        }

        return $sqlDoc;
    }

    /**
     * @param $sqlDoc
     * @param $sqlIndex
     */
    protected static function appendDocIndex(&$sqlDoc, $sqlIndex)
    {
        $index = $sqlIndex->getContent();
        $index = preg_split('/[\t\s]+/', $index);
        $sqlDoc[$index[0]] = array(
            'name' => $index[0],
            'keys' => $index[1],
            'unique' => (isset($index[3]) && $index[3] == 'unique') ? true : false,
        );
    }

    /**
     * Таблицы модели и подключение к БД
     *
     * @param $sqlDoc
     * @param Reflection\DocBlockReflection $docs
     * @param $parentClass
     */
    protected function appendDocTableNames(&$sqlDoc, $docs, $parentClass)
    {
        if (!$docs) return;
        $tablesNameTag = $docs->getTag('tablesName');
        if ($tablesNameTag) {
            $content = $tablesNameTag->getContent();
            if (method_exists($parentClass, $content)) {
                $sqlDoc['tables'] = (array)call_user_func(array($parentClass, $content));
            } else {
                $sqlDoc['tables'] = preg_split('/,/', $content, -1, PREG_SPLIT_NO_EMPTY);
            }
        }
//        if (!$sqlDoc['tables']) {
//            $this->usageError('TableName does not exist');
//        }

        // Если у модели друга БД
        $connectionIdTag = $docs->getTag('connectionId');
        if ($connectionIdTag) {
            $content = $connectionIdTag->getContent();
            if (method_exists($parentClass, $content)) {
                $sqlDoc['connectionId'] = call_user_func(array($parentClass, $content));
            } else {
                $sqlDoc['connectionId'] = $content;
            }
        }
        //else use default connection
    }

    /**
     * Получаем SQL различия модели и БД
     * @param $modelSqlInfoDocX
     * @return array
     */
    private function getModelSqlDiff($modelSqlInfoDocX)
    {
        $diff = array();

        if ($modelSqlInfoDocX['connectionId']) {
            $this->connectionID = $modelSqlInfoDocX['connectionId'];
        }

        if (!count($modelSqlInfoDocX['tables'])) {
            if (count($modelSqlInfoDocX['columns'])) {
                echo 'Warning - не задана таблица' . PHP_EOL;
            }
            return $diff;
        }

        $data = $modelSqlInfoDocX;
        unset($data['tables']);

        foreach ($modelSqlInfoDocX['tables'] as $tableName) {
            $table = $this->queryTableInfo($tableName);
            if (!$table) {
                $data['table'] = $tableName;
                $data['new'] = true; // создание табл
                $data['columnsRemove'] = array();
                $data['indexRemove'] = array();
                $diff[] = $data;
            } else {
                list($diffAdd, $diffRemove) = $this->getColumnsDef($table, $modelSqlInfoDocX);
                list($indexAdd, $indexRemove) = $this->getIndexDef($tableName, $modelSqlInfoDocX);

                if (count($diffRemove) || count($diffAdd) || count($indexAdd) || count($indexRemove)) {
                    $diff[] = array(
                        'table' => $tableName,
                        'new' => false,
                        'columns' => $diffAdd,
                        'columnsRemove' => $diffRemove,
                        'index' => $indexAdd,
                        'indexRemove' => $indexRemove,
                    );
                }
            }
        }

        return $diff;
    }

    /**
     * Различия в колонках
     * @param $table
     * @param $modelSqlInfoDocX
     *
     * @return array
     */
    private function getColumnsDef($table, $modelSqlInfoDocX)
    {
        $diffAdd = array_diff_key($modelSqlInfoDocX['columns'], $table->columns);
        $diffRemove = array_diff_key($table->columns, $modelSqlInfoDocX['columns']);
        return [$diffAdd, $diffRemove];
    }

    /**
     * Различия в индексе
     * @param $table
     * @param $modelSqlInfoDocX
     *
     * @return array
     */
    private function getIndexDef($tableName, $modelSqlInfoDocX)
    {
        $indexAdd = $modelSqlInfoDocX['index'];
        $indexRemove = [];
        $data = $this->getPostgresIndex($tableName);
        foreach($data as $item) {
            if (strpos($item['index_name'], $tableName.'_')===0) {
                $index = substr($item['index_name'], (strlen($tableName)+1));

                if (isset($indexAdd[$index])) {
                    unset($indexAdd[$index]);
                }
                elseif (!isset($indexAdd[$index]) && $index != 'pkey') {
                    $indexRemove[$index] = [
                        'name' => $index,
                        'keys' => $item['column_name'],
                        'unique' => false
                    ];
                }
            }
        }

        return [$indexAdd, $indexRemove];
    }
    /**
     * инфа о таблице
     * @param $tableName
     * @return mixed
     */
    private function queryTableInfo($tableName)
    {
        $db = $this->getDbConnection();
        return $db->schema->getTable($tableName);
    }

    /**
     * Получить поля таблицы  в нашем формате
     * @param $column
     * @return string
     */
    private static function getColumnProperty($column)
    {
        return $column->dbType;
    }

    /**
     * SQL Команды для миграции
     * @param $sqlInfo
     * @return array (columns' => array(),'pkey' => 'array/string', 'index' => array( name,keys,unique))
     */
    private function getSqlQuery($sqlInfo)
    {
        $up = $down = array();
        if ($this->connectionID) {
            $up[] = $down[] = '$this->setDbConnection(\Yii::app()->' . $this->connectionID . ');';
        }

        foreach ($sqlInfo as $item) {
            $table = $item['table'];
            // columns
            if ($item['new']) {
                $query = $this->templateSpace;
                if ($this->addComments) {
                    $query .= '/* ' . $item['tableComment'] . '*/' . $this->templateSpace;
                }
                $query .= '$this->createTable(\'' . $table . '\', array(' . $this->templateSpace;
                foreach ($item['columns'] as $k => $r) {
                    $query .= '    \'' . $k . '\' => \'' . $r . '\',';
                    if ($this->addComments) {
                        $query .= '    /* ' . $item['columnsComment'][$k] . '*/';
                    }
                    $query .= $this->templateSpace;
                }
                $query .= '));';
                $up[] = $query;
            } else {
                foreach ($item['columns'] as $k => $r) {
                    $query = '';
                    if ($this->addComments) {
                        $query .= '/*' . $item['columnsComment'][$k] . '*/' . $this->templateSpace;
                    }
                    $query .= '$this->addColumn(\'' . $table . '\', \'' . $k . '\', \'' . $r . '\');' . $this->templateSpace;
                    $up[] = $query;
                }

                foreach ($item['columnsRemove'] as $k => $r) {
                    $down[] = '$this->addColumn(\'' . $table . '\', \'' . $k . '\', \'' . self::getColumnProperty($r) . '\');' . $this->templateSpace;
                }
            }

            // primary key
            if (isset($item['pkey']) and $item['pkey']) {
                $name = $table . '_pkey';
                $columns = $item['pkey'];
                $up[] = '$this->addPrimaryKey(\'' . $name . '\',\'' . $table . '\',\'' . $columns . '\');';
                // $down[] = '$this->dropPrimaryKey(\''.$name.'\',\''.$table.'\');'; // dropPrimaryKey - ошибка в функции
            }

            // INDEX
            if (isset($item['index'])) {
                foreach ($item['index'] as $index) {
                    $up[] = '$this->createIndex(\'' . $table . '_' . $index['name'] . '\', \'' . $table . '\', \'' . $index['keys'] . '\', ' . ($index['unique'] ? 'true' : 'false') . ');';
                    $down[] = '$this->dropIndex(\'' . $table . '_' . $index['name'] . '\', \'' . $table . '\');';
                }
            }
            // INDEX
            if (isset($item['indexRemove'])) {
                foreach ($item['indexRemove'] as $index) {
                    $down[] = '$this->createIndex(\'' . $table . '_' . $index['name'] . '\', \'' . $table . '\', \'' . $index['keys'] . '\', ' . ($index['unique'] ? 'true' : 'false') . ');';
                    $up[] = '$this->dropIndex(\'' . $table . '_' . $index['name'] . '\', \'' . $table . '\');';
                }
            }

            // DOWN TABLE
            if ($item['new']) {
                $down[] = $this->templateSpace . '$this->dropTable(\'' . $table . '\');';
            } else {
                foreach ($item['columns'] as $k => $r) {
                    $down[] = '$this->dropColumn(\'' . $table . '\', \'' . $k . '\');' . $this->templateSpace;
                }

                foreach ($item['columnsRemove'] as $k => $r) {
                    $up[] = '$this->dropColumn(\'' . $table . '\', \'' . $k . '\');' . $this->templateSpace;
                }
            }
        }

        return array($up, $down);
    }

    /**
     * Текущее соединение с БД
     * @return \CDbConnection
     */
    protected function getDbConnection()
    {
        if ($this->_db !== null) {
            return $this->_db;
        } elseif (($this->_db = \Yii::app()->getComponent($this->connectionID)) instanceof \CDbConnection) {
            return $this->_db;
        }

        echo "Error: CMigrationCommand.connectionID '{$this->connectionID}' is invalid. Please make sure it refers to the ID of a CDbConnection application component." . PHP_EOL;
        exit(1);
    }

    /**
     * Получить поля таблицы  в нашем формате // columns
     * @param \core\db\schema\pgsql\Column $table
     *
     * @return array
     */
    private static function getTableProperty($table)
    {
//            [name] => count
//            [rawName] => "count"
//            [allowNull] =>
//            [dbType] => bigint
//            [type] => integer
//            [defaultValue] =>
//            [size] =>
//            [precision] =>
//            [scale] =>
//            [isPrimaryKey] =>
//            [isForeignKey] =>
//            [autoIncrement] =>
//            [comment] =>
//            [_e:CComponent:private] =>
//            [_m:CComponent:private] =>
        $columns = array();
        foreach ($table['columns'] as $column) {
            $columns[] = '"type" int2 NOT NULL';
        }
        return $columns;
    }

    private function getPostgresIndex($tableName) {
        $sql = '
select
    t.relname as table_name,
    i.relname as index_name,
    a.attname as column_name
from
    pg_class t,
    pg_class i,
    pg_index ix,
    pg_attribute a
where
    t.oid = ix.indrelid
    and i.oid = ix.indexrelid
    and a.attrelid = t.oid
    and a.attnum = ANY(ix.indkey)
    and t.relkind = \'r\'
    and t.relname like \''.$tableName.'\'
order by
    t.relname,
    i.relname;';
        $params = [];
        return $this->getDbConnection()->createCommand($sql)->queryAll(true, $params);
    }
}
