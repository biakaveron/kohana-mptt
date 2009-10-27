<?php defined('SYSPATH') OR die('No direct access allowed.');
/**
 *
 * Its a combination of a traditional MPTT tree and additional usefull data (parent id, level value)
 *
 * @package    CMS
 * @author     Brotkin Ivan (BIakaVeron) <BIakaVeron@gmail.com>
 * @copyright  Copyright (c) 2009 Brotkin Ivan
 *
 * @property Database												$db
 * @property Database_Query_Builder_Select  $db_builder
 */

abstract class ORM_MPTT extends ORM
{
	protected $_left_column = 'lft';
	protected $_right_column = 'rgt';
	protected $_level_column = 'lvl';
	protected $_scope_column = 'scope';
	protected $_parent_column = 'parent_id';
	protected $_sorting;

	public function __construct($id = NULL) {
		if (!isset($this->_sorting)) {
			$this->_sorting = array($this->_left_column => 'ASC');
		}
		parent::__construct($id);
	}

/*
 * Insert operations
 *
 */

	public function save() {
		// overload basic ORM::save() method
		if ( !$this->loaded()) {
			return $this->make_root();
		}
		else {
			return parent::save();
		}
	}

	public function make_root($scope = NULL) {
		// save node as root
		if ($this->loaded()) throw new Kohana_Exception('Cannot insert the same node twice');
		if (is_null($scope)) {
			$scope = self::get_next_scope();
		}
		elseif (self::scope_available($scope) === FALSE) {
			$scope = self::get_next_scope();
		}
		$this->{$this->_scope_column} = $scope;
		$this->{$this->_level_column} = 1;
		$this->{$this->_left_column} = 1;
		$this->{$this->_right_column} = 2;
		$this->{$this->_parent_column} = NULL;
		return parent::save();
	}

	public function make_child($id, $first = FALSE) {
		// inserts node as direct child for $id node
		$this->lock();
		if (!is_a($id, get_class($this))) {
			$id = self::factory($this->_object_name, $id);
		}
		if ($first === TRUE) {
			$lft = $id->{$this->_left_column}+1;
		}
		else {
			$lft = $id->{$this->_right_column};
		}
		$this->{$this->_scope_column} = $id->scope();
		$this->add_space($lft, 2);
		$this->{$this->_parent_column} = $id->primary_key_value;
		$this->{$this->_level_column} = $id->level() + 1;
		$this->{$this->_left_column} = $lft;
		$this->{$this->_right_column} = $lft+1;
		parent::save();
		$this->unlock();
		return $this;
	}

	public function insert_near($id, $before = FALSE) {
		// inserts node as next/prev sibling
		if ($this->loaded()) throw new Kohana_Exception('Cannot insert the same node twice');
		if ($this->size() > 2) throw new Kohana_Exception('Cannot use a node with children');
		if (!is_a($id, get_class($this))) {
			$id = self::factory($this->_object_name, $id);
		}
		if ($before) {
			$lft = $id->left();
		}
		else {
			$lft = $id->right() + 1;
		}
		$this->{$this->_scope_column} = $id->scope();
		$this->lock();
		$this->add_space($lft);
		$this->{$this->_left_column} = $lft;
		$this->{$this->_right_column} = $lft+1;
		$this->{$this->_parent_column} = $id->parent();
		$this->{$this->_level_column} = $id->level();
		parent::save();
		$this->unlock();
	}

	public function delete($id = NULL) {
		// deletes applied node with descendants
                if ( ! is_null($id) )
                {
                    $target = self::factory($this->_object_name, $id);
                }
                else
                {
                    $target = $this;
                }
                if ( ! $target->loaded()) return FALSE;

		$target->lock();
		DB::delete($target->_table_name)
			->where($target->_left_column," >=",$target->left())
			->where($target->_left_column," <= ",$target->right())
			->where($target->_scope_column, " = ", $target->scope())
			->execute($target->_db);
		$target->clear_space($target->left(), $target->size());
		$target->unlock();
	}

	public function move_to($id, $first = FALSE) {
		// moves current node with descendants to a node $id
		if (!is_a($id, get_class($this))) {
			$id = self::factory($this->_object_name, $id);
		}
		if ($this->is_in_descendants($id)) {
			throw new Kohana_Exception('Cannot move nodes to themself');
		}
		$ids = $this->subtree(TRUE)->primary_key_array();
		$lft = ($first==TRUE ? $id->left() + 1 : $id->right());
		$oldlft = $this->left();
		$level = $id->level() + 1;
		$delta = $lft - $this->left();
		if ($delta < 0) $delta = "(".$delta.")";
		$deltalevel = $level - $this->level();
		if ($deltalevel < 0) $deltalevel = "(".$deltalevel.")";
		$this->lock();
		// temporary setting scope to 0
		DB::update($this->_table_name)
			->in($this->primary_key, $ids)
			->set(array($this->_scope_column => 0))
			->execute($this->_db);
		$this->clear_space($oldlft, $this->size());
		$this->{$this->_scope_column} = $id->scope();
		$this->add_space($lft, $this->size());

		DB::update($this->_table_name)
			->in($this->primary_key, $ids)
			->set(array(
					$this->_left_column => DB::expr($this->_left_column. " + ".$delta),
					$this->_right_column => DB::expr($this->_right_column. " + ".$delta),
					$this->_level_column => DB::expr($this->_level_column. " + ".$deltalevel),
					$this->_scope_column => $id->scope(),
			))
			->execute($this->_db);
		$this->{$this->_parent_column} = $id->primary_key_value;
		parent::save();
		$this->unlock();
	}

	public function move_children_to($id, $first = FALSE) {
		// moves all descendants to $id node WITHOUT current node
		if (!$this->has_children()) return FALSE;
		if (!is_a($id, get_class($this))) {
			$id = self::factory($this->_object_name, $id);
		}
		$ids = $this->subtree(FALSE)->primary_key_array();
		$lft = ($first==TRUE ? $id->left() + 1 : $id->right());
		$oldlft = $this->left() + 1;
		$level = $id->level() + 1;
		$delta = $lft - $oldlft;
		if ($delta < 0) $delta = "(".$delta.")";
		$deltalevel = $level - $this->level() - 1;
		if ($deltalevel < 0) $deltalevel = "(".$deltalevel.")";
		$this->lock();
		DB::update($this->_table_name)
			->in($this->primary_key, $ids)
			->set(array($this->_scope_column => 0))
			->execute($this->_db);
		$this->clear_space($oldlft, $this->size() - 2);
		// this is need for correct add_space() work
		$this->{$this->_scope_column} = $id->scope();
		$this->add_space($lft, $this->size() - 2);

		DB::update($this->_table_name)
			->in($this->primary_key, $ids)
			->set(array(
					$this->_left_column => DB::expr($this->_left_column. " + ".$delta),
					$this->_right_column => DB::expr($this->_right_column. " + ".$delta),
					$this->_level_column => DB::expr($this->_level_column. " + ".$deltalevel),
					$this->_scope_column => $id->scope(),
				))
			->execute($this->_db);
		DB::update($this->_table_name)
			->set(array($this->_parent_column => $id->primary_key_value))
			->where($this->_level_column, "=", $id->level() + 1)
			->in($this->primary_key, $ids)
			->execute($this->_db);
		$this->unlock();
		$this->reload();
	}

/*
 * Retrieving info methods
 *
 */

	public function get_root($scope = NULL) {
		if (is_null($scope)) {
			// returns all roots
			return self::factory($this->_object_name)
				->where($this->_left_column, "=", 1)
				->find_all();
		}
		else {
			// only current root
			return self::factory($this->_object_name)
				->where($this->_scope_column, "=", $scope)
				->andwhere($this->_left_column, "=", 1)
				->find();
		}
	}

	public function get_parents($with_self = FALSE, $columns = FALSE) {
		$suffix = $with_self ? "= " : " ";
		if (is_array($columns)) {
			// returns applied columns only
			$query = DB::select();
			foreach ($columns as $column)
				$query->select($column);
			return $query
				->from($this->_table_name)
				->where($this->_left_column," <".$suffix, $this->left())
				->where($this->_right_column," >".$suffix, $this->right())
				->where($this->_scope_column, "=", $this->scope())
				->execute($this->_db);
		}
		else
		{
		// returns all current node parents as ORM objects
		return self::factory($this->_object_name)
			->where($this->_left_column," <".$suffix, $this->left())
			->where($this->_right_column," >".$suffix, $this->right())
			->where($this->_scope_column, "=", $this->scope())
			->find_all();
		}
	}

	public function get_parent() {
		if ($this->is_root()) return NULL;
		return self::factory($this->_object_name, $this->parent());
	}

	public function get_children() {
		// returns only direct children
		return self::factory($this->_object_name)
			->where($this->_left_column," >",$this->left())
			->where($this->_right_column," <",$this->right())
			->where($this->_scope_column, "=", $this->scope())
			->where($this->_level_column, "=", $this->level() + 1)
			->find_all();
	}

	public function get_subtree($with_parent = FALSE) {
		// return all descendants of current node
		$suffix = ($with_parent ? "= " : " ");
		return self::factory($this->_object_name)
			->where($this->_left_column," >",$suffix.$this->left())
			->where($this->_right_column," <",$suffix.$this->right())
			->where($this->_scope_column, "=", $this->scope())
			->find_all();
	}
	
	public function get_fulltree($use_scope = TRUE) {
		// returns full tree (with or without scope checking)
		$result = self::factory($this->_object_name);
		if ($use_scope) 
			$result->where($this->_scope_column, "=", $this->{$this->_scope_column});
		if ($use_scope == FALSE) 
			$result->orderby($this->_scope_column, 'ASC')
				->orderby($this->_left_column, 'ASC');
		return $result->find_all();
	}

	public function get_leaves() {
		// returns only leaves of current node
		return self::factory($this->_object_name)
			->where($this->_left_column," >",$suffix.$this->left())
			->where($this->_right_column," <",$suffix.$this->right())
			->where($this->_left_column, "=", DB::expr($this->_right_column." - 1"))
			->where($this->_scope_column, "=", $this->scope())
			->find_all();
	}

/*
 * Simple methods for getting/setting primary info
 * 
 */

	public function set_title($title) {
		$this->title = $title;
		return $this;
	}

 	public function left() {
		return $this->{$this->_left_column};
	}

	public function right() {
		return $this->{$this->_right_column};
	}

	public function level() {
		return $this->{$this->_level_column};
	}

	public function scope() {
		return $this->{$this->_scope_column};
	}

	public function parent() {
		return $this->{$this->_parent_column};
	}

	public function size() {
		return $this->{$this->_right_column} - $this->left() + 1;
	}

	public function count() {
		return ($this->size() - 2)/2;
	}

	public function has_children() {
		return ($this->size() > 2);
	}

	public function is_parent($id) {
		// is current node a direct parent of $id node
		if (!is_a($id, get_class($this))) {
			$id = self::factory($this->_object_name, $id);
		}
		return $id->{$this->_parent_column} == $this->primary_key_value;
	}

	public function is_child($id) {
		// is current node a direct child of $id node
		if (!is_a($id, get_class($this))) {
			$id = self::factory($this->_object_name, $id);
		}
		return $this->{$this->_parent_column} == $id->primary_key_value;
	}

	public function is_in_descendants($id) {
		// is current node one of a $id node child
		if (!is_a($id, get_class($this))) {
			$id = self::factory($this->_object_name, $id);
		}
		if ($this->scope() != $id->scope()) return FALSE;
		if ($this->left() <= $id->left()) return FALSE;
		if ($this->right() >= $id->right()) return FALSE;
		return TRUE;
	}

	public function is_in_parents($id) {
		// is current node one of a $id node parents
		if (!is_a($id, get_class($this))) {
			$id = self::factory($this->_object_name, $id);
		}
		return $id->is_in_descendants($this);
	}

	public function is_neighbor($id) {
		// is current node neighbor of $id node (the same direct parent)
		if (!is_a($id, get_class($this))) {
			$id = self::factory($this->_object_name, $id);
		}
		return ($this->parent() == $id->parent());
	}

	public function is_root() {
		// is current node a root node
		return $this->level() == 1;
	}

/*
 * Support methods
 *
 */

	protected function add_space($start, $size = 2) {
		// add space for adding/inserting nodes
		// $this->scope should be set before adding space!
		DB::update($this->_table_name)
			->set(array($this->_left_column => DB::expr($this->_left_column.' + '.$size)))
			->where($this->_left_column," >= ",$start)
			->where($this->_scope_column, "=", $this->scope())
			->execute($this->_db);
		DB::update($this->_table_name)
			->set(array($this->_right_column => DB::expr($this->_right_column.' + '.$size)))
			->where($this->_right_column," >= ",$start)
			->where($this->_scope_column, "=", $this->scope())
			->execute($this->_db);
	}

	protected function clear_space($start, $size = 2) {
		// remove space after deleting/moving node
		DB::update($this->_table_name)
			->set(array($this->_left_column => DB::expr($this->_left_column.' - '.$size)))
			->where($this->_left_column," >= ",$start)
			->where($this->_scope_column, "=", $this->scope())
			->execute($this->_db);
		DB::update($this->_table_name)
			->set(array($this->_right_column => DB::expr($this->_right_column.' - '.$size)))
			->where($this->_right_column," >= ",$start)
			->where($this->_scope_column, "=", $this->scope())
			->execute($this->_db);
	}

	protected function lock() {
		// lock table
		//$this->_db->query('lock','LOCK TABLE '.$this->_table_name.' WRITE');
		DB::query('lock', 'LOCK TABLE '.$this->_table_name.' WRITE')->execute($this->_db);
	}

	protected function unlock() {
		// unlock tables
		DB::query('unlock','UNLOCK TABLES')->execute($this->_db);
	}

	protected function scope_available($scope) {
		// checking for supplied scope available
		return ! self::factory($this->_object_name)
			->where($this->_scope_column, "=", $scope)
			->count_all();
	}

	protected function get_next_scope() {
		// returns available value for scope
		$scope = DB::select(DB::expr('IFNULL(MAX(`'.$this->_scope_column.'`), 0) as scope'))
					->from($this->_table_name)
					->execute($this->_db)
					->current();
		if ($scope AND intval($scope['scope'])>0) return intval($scope['scope'])+1;
		return 1;
	}

	public function __get($column) {
		if ($column === 'parent')
			return $this->get_parent();
		elseif ($column === 'parents')
			return $this->get_parents();
		elseif ($column === 'children')
			return $this->get_children();
		elseif ($column === 'leaves')
			return $this->get_leaves();
		elseif ($column === 'subtree')
			return $this->get_subtree();
		elseif ($column === 'fulltree')
			return $this->get_fulltree();
		else return parent::__get($column);
	}

}