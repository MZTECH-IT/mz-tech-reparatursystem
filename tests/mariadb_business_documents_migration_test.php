<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dbName='mztech_business_documents_test';
$pdo=new PDO('mysql:host=127.0.0.1;port=33079;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>true]);
$template=(string)file_get_contents($root.'/deployment/tools/repair_account_runner_template.php');
$start=strpos($template,'function deployment_split_sql');$end=strpos($template,'function deployment_execute_sql',$start?:0);
if($start===false||$end===false)throw new RuntimeException('SQL-Parser fehlt.');eval(substr($template,$start,$end-$start));
$execute=static function(PDO $db,string $path):array{$rows=[];foreach(deployment_split_sql((string)file_get_contents($path)) as $sql){$keyword=strtoupper((string)strtok(ltrim($sql)," \t\r\n"));if(in_array($keyword,['SELECT','SHOW','DESCRIBE'],true)){$q=$db->query($sql);$rows=array_merge($rows,$q->fetchAll());$q->closeCursor();}else{$db->exec($sql);}}return $rows;};
try{
 $pdo->exec("DROP DATABASE IF EXISTS `$dbName`");$pdo->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$pdo->exec("USE `$dbName`");
 foreach([
  'CREATE TABLE users(id INT UNSIGNED AUTO_INCREMENT,full_name VARCHAR(190),PRIMARY KEY(id)) ENGINE=InnoDB',
  'CREATE TABLE customers(id INT UNSIGNED AUTO_INCREMENT,first_name VARCHAR(100),last_name VARCHAR(100),PRIMARY KEY(id)) ENGINE=InnoDB',
  'CREATE TABLE companies(id INT UNSIGNED AUTO_INCREMENT,company_name VARCHAR(190),PRIMARY KEY(id)) ENGINE=InnoDB',
  'CREATE TABLE settings(setting_key VARCHAR(100) PRIMARY KEY,setting_value TEXT) ENGINE=InnoDB',
  'CREATE TABLE repairs(id INT UNSIGNED AUTO_INCREMENT,customer_id INT UNSIGNED NOT NULL,device_type VARCHAR(100) NOT NULL,advance_payment DECIMAL(10,2) NOT NULL DEFAULT 0,working_hours DECIMAL(8,2) NOT NULL DEFAULT 0,hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 79,labor_cost DECIMAL(12,2) NOT NULL DEFAULT 0,performed_work TEXT,internal_notes TEXT,price DECIMAL(10,2),PRIMARY KEY(id)) ENGINE=InnoDB',
  'CREATE TABLE parts(id INT UNSIGNED AUTO_INCREMENT,name VARCHAR(255) NOT NULL,purchase_price DECIMAL(10,2),selling_price DECIMAL(10,2),PRIMARY KEY(id)) ENGINE=InnoDB',
  'CREATE TABLE repair_parts(id INT UNSIGNED AUTO_INCREMENT,repair_id INT UNSIGNED NOT NULL,part_id INT UNSIGNED NOT NULL,quantity INT NOT NULL DEFAULT 1,purchase_price_at_time DECIMAL(10,2),selling_price_at_time DECIMAL(10,2),PRIMARY KEY(id)) ENGINE=InnoDB',
  "INSERT INTO users(full_name) VALUES ('TEST Admin')","INSERT INTO customers(first_name,last_name) VALUES ('TEST','Kunde')",
  "INSERT INTO repairs(customer_id,device_type,advance_payment) VALUES (1,'Fernseher',0)",
  "INSERT INTO parts(name,purchase_price,selling_price) VALUES ('T-Con-Platine',80.00,NULL)",
 ] as $sql)$pdo->exec($sql);
 $pre=$execute($pdo,$root.'/sql/business_documents_preflight.sql');foreach($pre as $row){if(($row['status']??'')==='FEHLT')throw new RuntimeException('Preflight meldet FEHLT: '.json_encode($row));}
 $execute($pdo,$root.'/sql/business_documents_migration.sql');
 $execute($pdo,$root.'/sql/business_documents_migration.sql');
 $post=$execute($pdo,$root.'/sql/business_documents_postcheck.sql');foreach($post as $row){if(in_array(($row['status']??''),['FEHLT','ABWEICHUNG'],true))throw new RuntimeException('Postcheck-Abweichung: '.json_encode($row));}
 $price=$pdo->query('SELECT automatic_selling_price,selling_price,markup_percent FROM parts WHERE id=1')->fetch();
 if($price['automatic_selling_price']!=='88.00'||$price['selling_price']!=='88.00'||$price['markup_percent']!=='10.00')throw new RuntimeException('10-Prozent-Kalkulation ist falsch.');
 if((int)$pdo->query("SELECT COUNT(*) FROM number_ranges WHERE doc_type IN ('ANG','RE','GS')")->fetchColumn()!==3)throw new RuntimeException('Nummernkreise fehlen.');
 echo "MARIADB_BUSINESS_DOCUMENTS_MIGRATION_OK\n";
}finally{$pdo->exec('USE mysql');$pdo->exec("DROP DATABASE IF EXISTS `$dbName`");}
