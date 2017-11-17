<?php

class Model {
    private $columns, $dbName, $tableName;

    function __construct($data){
        $this->tableName = $this->tableName ? $this->tableName : $this->getTableName();
        $this->dbName = CFG_DB_DBNAME;
        $this->getTableProperties();
        if(!is_null($data)) $this->loadFromDataArray($data);
    }

    public function __get($name){
		foreach ($this->columns as $column) {
		    if($column->Name == $name) return $column->Value;
		}
        foreach ($this->columns as $column) {
            if($column->IsForeignKey && get_class($column->ForeignObject) == $name)
                return $column->ForeignObject;
        }
		trigger_error("Mode > Unkown property '$name' in object/table '$this->tableName'");
	}

    public function __set($name, $value){
        foreach ($this->columns as $column) {
		    if($column->Name == $name){
                if($column->Value === $value) return $value;
                $column->Value = $value; // IDEA: podría verificar basandose en $column->DataType
                $column->HasChanged = true;
                if($column->IsForeignKey && $column->ForeignObject)
                    $column->ForeignObject->GetById($value);
                return $value;
            }
		}
        trigger_error("Mode > Unkown property '$name' in object/table '$this->tableName'");
	}

    private function getTableProperties(){
        $this->getTableColumns();
    }

    private function getTableColumns(){
        $query = "SELECT COLUMN_NAME, IS_NULLABLE, DATA_TYPE, EXTRA, COLUMN_KEY, TABLE_NAME, TABLE_SCHEMA
                  FROM information_schema.`COLUMNS`
				  WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?";
		$res = SDB::EscRead($query, array($this->dbName, $this->tableName));
		$this->columns = array();
		foreach ($res as $columnProperties){
            $tCol = new Column($columnProperties);
			array_push($this->columns, $tCol);
		}
    }

    protected function getTableName(){
        $tableName = get_class($this);
		$query = "SELECT *
				  FROM information_schema.`TABLES`
				  WHERE table_schema = ?
				  AND table_name = ?
				  LIMIT 1";
		$res = SDB::EscRead($query, array(CFG_DB_DBNAME, Util::CamelToSnakeCase($tableName)));
		if($res && count($res) > 0)
			return Util::CamelToSnakeCase($tableName);
		else
			trigger_error("ModelEx\getTableName > Table '$tableName' not found on database.");
	}

    public function GetById($id){
        $pkArray = array_map(function($pk){
            return "$pk = ?";
        }, $this->getPKarray());
        $selectCondition = implode(" AND ", $pkArray);
        $queryColumns = implode(', ', $this->getColArray());
        $query = "SELECT $queryColumns FROM $this->tableName WHERE $selectCondition";
        $id = is_array($id) ? $id : array($id);
        $res = SDB::EscRead($query, $id);
        if($res){
            $this->loadFromDb($res[0]);
            return true;
        }
        return false;
    }

    private function loadFromDb($data){
        foreach ($this->columns as $column) {
            if(array_key_exists($column->NameInDb, $data))
                $column->Value = $data[$column->NameInDb];
        }
    }

    public function Save(){
        if(!$this->checkNullables()){
            trigger_error("Model\Insert > Trying to insert object with a null property in a non-nullable field.");
            return false;
        }

        foreach ($this->columns as $column) {
            if($column->IsPrimaryKey && is_null($column->Value))
                return $this->insertObject();
        }
        return $this->updateObject();
    }

    private function loadFromDataArray($data){
        if(!Util::IsAssociativeArray($data)) trigger_error("Model\Load > Provided array is not an associative array.");
        foreach ($data as $key => $value) {
            foreach ($this->columns as $column) {
                if($key === $column->Name || $key === $column->NameInDb) $column->Value = $value;
                if($column->IsForeignKey && $column->ForeignObject) $column->ForeignObject->GetById($value);
            }
        }
        return true;
    }

    public function GetAssosiativeArray($dbName = false){
        $assArr = array();
        foreach ($this->columns as $column) {
            $assArr[$dbName ? $column->NameInDb : $column->Name] = $column->Value;
        }
        return $assArr;
    }

    private function insertObject(){
        $pairArray = $this->getPairArray(true);
        $columnString = implode(", ", array_keys($pairArray));
        $valueString = implode(", ", array_map(function($k){
            return ":$k";
        }, array_keys($pairArray)));

        $query = "INSERT INTO $this->tableName ($columnString) VALUES ($valueString)";
        $res = SDB::EscWrite($query, $pairArray);
        if($res){
            foreach ($this->columns as $column) {
                if($column->AutoIncrement)
                    $column->Value = $res;
            }
        }
        return !!$res;
    }

    private function checkNullables(){
        foreach ($this->columns as $col) {
            if(!$col->IsNullable && $col->Value === null && (!$col->AutoIncrement || $col->HasChanged)) return false;
        }
        return true;
    }

    private function updateObject (){
        if(!$this->hasChanges()) return true;
        $setString = implode(", ", array_map(function($column){
            return "$column->NameInDb = :$column->NameInDb";
        }, array_filter($this->columns, function($column){
            return !$column->IsPrimaryKey && $column->HasChanged;
        })));
        $conditionString = implode(" AND ", array_map(function($column){
            return "$column->NameInDb = :$column->NameInDb";
        }, array_filter($this->columns, function($column){
            return $column->IsPrimaryKey;
        })));
        $parameterArray = array();
        foreach ($this->columns as $column) {
            if($column->IsPrimaryKey || $column->HasChanged)
                $parameterArray[$column->NameInDb] = $column->Value;
        }
        $query = "UPDATE $this->tableName SET $setString WHERE $conditionString";
        return SDB::EscWrite($query, $parameterArray);
    }

    private function hasChanges(){
        foreach ($this->columns as $column) {
            if($column->HasChanged) return true;
        }
        return false;
    }

    private function getPKarray(){
        $primaryKeys = array();
        foreach ($this->columns as $column) {
            if($column->IsPrimaryKey)
                array_push($primaryKeys, $column->NameInDb);
        }
        return $primaryKeys;
    }

    private function getPairArray($noNull = false, $onlyChanged = false, $onlyPK = false){
        $pairArray = array();
        foreach ($this->columns as $column) {
            if( ($noNull && is_null($column->Value)) ||
                ($onlyChanged && !$column->HasChanged) ||
                ($onlyPK && !$column->IsPrimaryKey) )
                    continue;
            $pairArray[$column->NameInDb] = $column->Value;
        }
        return $pairArray;
    }

    private function getColArray(){
        return array_map(function($col){
            return $col->NameInDb;
        }, $this->columns);
    }

    public static function Create($tableName, $data = null){
        $tableName = Util::SnakeToCamelCase($tableName);
		if(!class_exists($tableName))
			eval("class $tableName extends Model {}");
		return new $tableName($data);
	}
}

class Column {
	public $Name, $IsForeignKey, $IsPrimaryKey, $Value, $HasChanged, $DataType, $IsNullable, $AutoIncrement, $NameInDb, $ForeignObject;

    function __construct($properties){
        $this->setProperties($properties);
        if($this->IsForeignKey)
            $this->buildForeignObject($properties["TABLE_NAME"], $properties["TABLE_SCHEMA"]);
    }

    private function setProperties($properties){
        $this->NameInDb = $properties["COLUMN_NAME"];
        $this->Name = Util::SnakeToCamelCase($this->NameInDb);
        $this->IsForeignKey = strpos($properties["COLUMN_KEY"], "MUL") !== FALSE;
        $this->IsPrimaryKey = strpos($properties["COLUMN_KEY"], "PRI") !== FALSE;
        $this->HasChanged = false;
        $this->DataType = $properties["DATA_TYPE"];
        $this->IsNullable = strpos($properties["IS_NULLABLE"], "YES") !== FALSE;
        $this->AutoIncrement = strpos($properties["EXTRA"], "auto_increment") !== FALSE;
    }

    private function buildForeignObject($tableName, $tableSchema){
        $query = "SELECT
                	  REFERENCED_TABLE_NAME,
                	  REFERENCED_COLUMN_NAME
                  FROM
                	  information_schema.KEY_COLUMN_USAGE
                  WHERE
                	  TABLE_NAME = ?
                  AND TABLE_SCHEMA = ?
                  AND COLUMN_NAME = ?
                  AND REFERENCED_COLUMN_NAME IS NOT NULL";
        $res = SDB::EscRead($query, array($tableName, $tableSchema, $this->NameInDb));
        $this->ForeignObject =  Model::Create($res[0]["REFERENCED_TABLE_NAME"]);
    }

    function __toString(){
        return (string)$this->Value;
    }
}

class DBO {
    private $table,$fields,$values,$joins,$where,$orderby,$limit,$offset;
    private $fieldHolders, $valueHolders;
    private $operation;
    public $Error;
    private $lastShackle;

    protected $con;

	public function __construct(){
		try{
			$this->con = new PDO(CFG_DB_DRIVER.":host=".CFG_DB_HOST.";dbname=".CFG_DB_DBNAME.";charset=".CFG_DB_CHARSET, CFG_DB_USER, CFG_DB_PASSWORD);
			$this->con->prepare("SET TEXTSIZE 9145728")->execute();
		}catch(PDOException $e) {
			echo "<h3 style='text-align:center;background-color:black;color:red;font-weight:bolder;border:1px solid black;'>{$e->getMessage()}</h3>";
		}
	}

    private $errorMsg = [
        "Operation mode not set or not valid.",
        "Invalid paramenter for operation. Expecting associative array.",
        "Unknown operation.",
        "Method `And`|`Or` only can be used after `Where`.",
        "Invalid parameter. Expecting 3 element array or array with 3 elements arrays.",
        "Can't call Where method two times in a chain. Use _And|_Or instead.",
		"Limit value must be numeric.",
		"OrderBy parameter must be a 2 elements array or an array containing two elements arrays."
        ];

    public function Select($table){
        $this->operation = "SELECT";
        $this->table = $table;
        return $this;
    }

    public function Insert($table){
        $this->operation = "INSERT";
        $this->table = $table;
        return $this;
    }

    public function Update($table){
        $this->operation = "UPDATE";
        $this->table = $table;
        return $this;
    }

    public function Delete($table){
        $this->operation = "DELETE";
        $this->table = $table;
        return $this;
    }

    public function SetData($data){
        if(!$this->operation || $this->operation == "DELETE") return $this->setError(0);
        if(($this->operation == "UPDATE" || "INSERT" ) && !is_array($data) && !Util::IsAssociativeArray($data)) return $this->setError(1);

        switch ($this->operation){
            case "SELECT":
                $this->fields = implode(", ",$data);
                break;
            case "INSERT":
                $this->fields = implode(", ",array_keys($data));
                $this->valueHolders = "";
                foreach($data as $k => $v){
                    $this->valueHolders .= ":v{$k}, ";
                    $data[":v".$k] = $v;
                    unset($data[$k]);
                }
                $this->valueHolders = substr($this->valueHolders,0,strrpos($this->valueHolders,", "));
                $this->values = $data;
                break;
            case "UPDATE":
                $this->fields = "";
                foreach($data as $k => $v){
                    $this->fields .= "{$k} = :v{$k}, ";
                    $data[":v".$k] = $v;
                    unset($data[$k]);
                }
                $this->fields = substr($this->fields,0,strrpos($this->fields,", "));
                $this->values = $data;
                break;
            default:
                return $this->setError(2);
        }
        return $this;
    }

	public function Limit($limitNumber){
		if(!is_numeric($limitNumber)) return $this->setError(6);
		$this->limit = $limitNumber;
		return $this;
	}

	public function Offset($offsetNumber){
		if(!is_numeric($offsetNumber)) return $this->setError(6);
		$this->offset = $offsetNumber;
		return $this;
	}

	public function OrderBy($orderByArray){
		if(!is_array($orderByArray)) return setError(7);
		if(is_array($orderByArray[0])){
            foreach($orderByArray as $k => $v){
                $orderByArray[$k] = implode(" ", $v);
            }
			$this->orderby = implode(", ", $orderByArray);
        }else{
            $this->orderby = implode(" ", $orderByArray);
        }
		return $this;
	}

    public function Exec($fetchType = PDO::FETCH_ASSOC){
        $query = $this->buildQuery();
        if(is_array($this->joins))
            foreach($this->joins as $join){
                $query .= " " . $join;
            }
        $query .= $this->where ? " WHERE ".$this->where : "";
        $query .= $this->orderby ? " ORDER BY " . $this->orderby : "";
        $query .= $this->limit ? " LIMIT " . $this->limit : "";
        $query .= $this->offset ? " OFFSET " . $this->offset : "";
        $sth = $this->con->prepare($query);
        $result = $sth->execute($this->values);
        switch($this->operation){
        	case "UPDATE":
        	case "DELETE":
        		return $result;
        		break;
        	case "INSERT":
        		return $result ? $this->con->lastInsertId() : false;
        		break;
        	case "SELECT":
        		//return $result ? $sth->fetchAll($fetchType) : false;
				return $this->resultToModel($sth->fetchAll($fetchType));
        		break;
        }
    }

	private function resultToModel($result){
        if(!$result) return false;
		$objArray = array();
		foreach ($result as $key => $value) {
			$objArray[$key] = Model::Create($this->table, $value);
		}
		return $objArray;
	}

    private function buildQuery(){
        $ret = "";
        switch($this->operation){
            CASE "SELECT":
				$fields = !$this->fields ? "*" : $this->fields;
                $ret = "SELECT {$fields} FROM {$this->table}";
                break;
            CASE "INSERT":
                $ret = "INSERT INTO {$this->table} ({$this->fields}) VALUES ($this->valueHolders)";
                break;
            CASE "UPDATE":
                $ret = "UPDATE {$this->table} SET {$this->fields}";
                break;
            CASE "DELETE":
                $ret = "DELETE FROM {$this->table}";
                break;
            default:
                return $this->setError(2);
        }
        return $ret;
    }

    public function Where($conditionData,$logicalOperator = null){
        if($this->operation == "INSERT") return $this->setError(0);
        if(!is_array($conditionData)) return $this->setError(4);
        if($this->where && !$logicalOperator) return $this->setError(5);
        $this->where = $logicalOperator ? $this->where . $logicalOperator : $this->where;
        if(is_array($conditionData[0])){
            $multiClause = " (";
            foreach($conditionData as $w){
                $multiClause .= $this->buildWhereClause($w) . " AND ";
            }
            $multiClause = substr($multiClause,0,strrpos($multiClause," AND "));
            $this->where .= $multiClause . ")";
        }else{
            $this->where .= $this->buildWhereClause($conditionData);
        }
        $this->lastShackle = "Where";
        return $this;
    }

    private function buildWhereClause($arrClause){
        $identifier = ":we".substr_count($this->where,":we");
        $whereClause = "{$arrClause[0]} {$arrClause[1]} {$identifier}{$arrClause[0]}";
        $this->values[$identifier.$arrClause[0]] = $arrClause[2];
        return $whereClause;
    }

    public function _And($conditionData){
        if( !($this->lastShackle == "Where") ) return $this->setError(3);
        $this->Where($conditionData," AND ");
        return $this;
    }

    public function _Or($conditionData){
        if( !($this->lastShackle == "Where") ) return $this->setError(3);
        $this->Where($conditionData," OR ");
        return $this;
    }

    private function setError($msg){
        $this->Error = $this->errorMsg[$msg];
        return false;
    }
}

class SDB{
	/** @var \PDO */
	protected static $con;
	protected static $initialized = false;
	public static $LastError;

	private static function initialize(){
	    if(self::$initialized) return;
		self::$con = new PDO(CFG_DB_DRIVER.":host=".CFG_DB_HOST.";dbname=".CFG_DB_DBNAME.";charset=".CFG_DB_CHARSET, CFG_DB_USER, CFG_DB_PASSWORD);
		self::$con->prepare("SET TEXTSIZE 9145728")->execute();
		self::$initialized = true;
	}

	public static function Read($query,$arrayType = PDO::FETCH_ASSOC){
	    self::initialize();
		$STH = self::$con->prepare($query);
		$result = $STH->execute();
		if($result){
			return $STH->fetchAll($arrayType);
		}else{
			return false;
		}
	}

	public static function Write($query){
	    self::initialize();
		$STH = self::$con->prepare($query);
		$result = $STH->execute();
		return $result;
	}

	/**
	 * @param string $query
	 * @param array $data
	 * @param int $arrayType
	 * @param bool $debug
	 * @return array|bool
	 */
	public static function EscRead($query, $data, $arrayType = PDO::FETCH_ASSOC, $debug = false){
		self::initialize();
		$STH = self::$con->prepare($query);
		$result = $STH->execute($data);
		self::$LastError = $STH->errorInfo();
		if($debug){
			print "<pre>";
			$STH->debugDumpParams();
			print "</pre>";
			print "<pre>";
			var_dump($data);
			print "</pre>";
		}
		if($result){
			return $STH->fetchAll($arrayType);
		}else{
			return false;
		}
	}

	/**
	 * @param string $query
	 * @param array $data
	 * @param bool $debug
	 * @return bool
	 */
	public static function EscWrite($query,$data, $debug = false){
		self::initialize();
		$STH = self::$con->prepare($query);
		$result = $STH->execute($data);
		self::$LastError = $STH->errorInfo();
		if($debug){
			print "<pre>";
			$STH->debugDumpParams();
			print "</pre>";
			print "<pre>";
			var_dump($data);
			print "</pre>";
			print "<pre>";
			var_dump($STH->errorInfo());
			print "</pre>";
		}
		if(strpos($query, "INSERT") === 0) return self::$con->lastInsertId();
		return !!$result;
	}

	public static function CloseConnection(){
		self::$con = null;
		self::$initialized = false;
	}
}

class Util {
    public static function CamelToSnakeCase($input){
        $input = preg_replace("/([a-z 1-9])([A-Z])/", '$1_$2',$input);
        return strtolower($input);
    }

    public static function SnakeToCamelCase($input){
        return ucfirst(preg_replace_callback("/(_)([a-z])/", function($matches){
            return strtoupper($matches[2]);
        }, $input));
    }

    public static function IsAssociativeArray($arr)	{
		if (array() === $arr) return false;
		return array_keys($arr) !== range(0, count($arr) - 1);
	}
}
