Создание файлов-миграции для Yii по документации из моделей
==================

Реализовано

* Подхватывает данные из extend модели
* Создает файл миграции 
* Определяет добавленные и удаленные атрибуты
* Создание составных индексов и ключей
* Шаблон для создания миграции $templateFile
* Миграция по файлу содержащий модель

Планируется 

* определять измененные атрибуты

Для интеграции с PhpShtorm:

    Settings->Remote SSH External Tools->Add
    В поле "Program" -> php
    В поле "parameters" -> yiic.php modelMigrate byfile "$FilePathRelativeToProjectRoot$" ]
    * где "yiic.php" - укажите ваш путь к запуску
    Запуск с горячей клавиши в текущем файле - запустит миграцию найденной модели в этом файле


В конфиге commandMap прописать

    'modelmigrate' => array('class' => '\extensions\ModelMigration\ModelMigrationCommand', 'connectionID' => 'db')
  
запуск, в корне проекта, указать класс:

    $ php yiic.php modelmigrate index "app\services\statistics\UserAction"



-----------------
Правила документирования модели

Возможно укзать соединение с БД (или используется текущее соединение по умолчанию)

    @connectionId getDbStats     !!! вызов функции если есть
    @connectionId db_stats       !!! Используемый компонент для подключения к БД

Атрибуты

    @property integer    $attribute      строго после описния поля в квадратных скобках задается параметры sql  [int2 NOT NULL]
    @property string     $attribute2     Тип     [varchar(255)]

Если нужно исключить атрибут взаимствованный из extends класса

    @exclude  $attribute

Составной первичный ключ

    @sqlPrimary     type,date,company_id,mask
    !!! или можно в атрибутах было прописать так
    @property id     $id     первичный ключ     [pk]

Индексы

    @sqlIndex    company_id_idx  company_id

Уникальный индекс (Unique)

    @sqlIndex    company_id_idx  company_id  true
