<?php
/**
 *
 * Group Subscription. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2017, Steve Guidetti, https://github.com/stevotvr
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace stevotvr\groupsub\operator;

use phpbb\config\config;
use stevotvr\groupsub\entity\subscription_interface as entity;
use stevotvr\groupsub\exception\out_of_bounds;

/**
 * Group Subscription subscription operator.
 */
class subscription extends operator implements subscription_interface
{
	/**
	 * @var \stevotvr\groupsub\operator\product_interface
	 */
	protected $prod_operator;

	/**
	 * The root phpBB path.
	 *
	 * @var string
	 */
	protected $root_path;
	/**
	 * The script file extension.
	 *
	 * @var string
	 */
	protected $php_ext;

	/**
	 * The offset for querying subscriptions.
	 *
	 * @var int
	 */
	protected $start = 0;

	/**
	 * The limit for querying subscriptions.
	 *
	 * @var int
	 */
	protected $limit = 0;

	/**
	 * The list of filters for building the WHERE clause.
	 *
	 * @var array
	 */
	protected $filters = array();

	/**
	 * The ORDER BY clause.
	 *
	 * @var string
	 */
	protected $sort = null;

	/**
	 * The grace period in seconds.
	 *
	 * @var int
	 */
	protected $grace;

	/**
	 * Set up the operator.
	 *
	 * @param \phpbb\config\config                          $config
	 * @param \stevotvr\groupsub\operator\product_interface $prod_operator
	 */
	public function setup(config $config, product_interface $prod_operator)
	{
		$this->grace = (int) $config['stevotvr_groupsub_grace'] * 86400;
		$this->prod_operator = $prod_operator;
	}

	/**
	 * Set the phpBB installation path information.
	 *
	 * @param string $root_path The root phpBB path
	 * @param string $php_ext   The script file extension
	 */
	public function set_path_info($root_path, $php_ext)
	{
		$this->root_path = $root_path;
		$this->php_ext = $php_ext;
	}

	public function set_start($start)
	{
		$this->start = (int) $start;
		return $this;
	}

	public function set_limit($limit)
	{
		$this->limit = (int) $limit;
		return $this;
	}

	public function set_user($user_id)
	{
		$this->filters['s.user_id'] = (int) $user_id;
		return $this;
	}

	public function set_product($prod_id)
	{
		$this->filters['s.gs_id'] = (int) $prod_id;
		return $this;
	}

	public function set_sort($field, $desc = false)
	{
		if (!$field)
		{
			$this->sort = null;
			return $this;
		}

		$this->sort = $field . ($desc ? ' DESC' : ' ASC');
		return $this;
	}

	public function get_subscriptions()
	{
		$where = array();
		foreach ($this->filters as $key => $value)
		{
			if (!$value)
			{
				continue;
			}

			$where[] = $key . ' = ' . $value;
		}

		return $this->get_subscription_rows(implode(' AND ', $where), $this->sort, $this->limit, $this->start);
	}

	public function get_subscription($sub_id)
	{
		$subscriptions = $this->get_subscription_rows('s.sub_id = ' . (int) $sub_id);

		if (!count($subscriptions))
		{
			throw new out_of_bounds('sub_id');
		}

		return $subscriptions[0];
	}

	/**
	 * Get subscription data from the database.
	 *
	 * @param string|null $where The WHERE clause
	 * @param string|null $sort  The ORDER BY clause
	 * @param int         $limit The maximum number of rows to get
	 * @param int         $start The row at which to start
	 *
	 * @return array Array of subscription data
	 *                     product	string
	 *                     username	string
	 *                     entity	\stevotvr\groupsub\entity\subscription_interface
	 */
	protected function get_subscription_rows($where = null, $sort = null, $limit = 0, $start = 0)
	{
		$subscriptions = array();

		$sql_ary = array(
			'SELECT'	=> 's.*, p.gs_name, u.username',
			'FROM'		=> array($this->sub_table => 's'),
			'LEFT_JOIN'	=> array(
				array(
					'FROM'	=> array($this->product_table => 'p'),
					'ON'	=> 's.gs_id = p.gs_id',
				),
				array(
					'FROM'	=> array(USERS_TABLE => 'u'),
					'ON'	=> 's.user_id = u.user_id',
				),
			),
		);

		if ($where)
		{
			$sql_ary['WHERE'] = $where;
		}

		if ($sort)
		{
			$sql_ary['ORDER_BY'] = $sort;
		}

		$sql = $this->db->sql_build_query('SELECT', $sql_ary);
		$result = $limit ? $this->db->sql_query_limit($sql, $limit, $start) : $this->db->sql_query($sql);
		while ($row = $this->db->sql_fetchrow($result))
		{
			$subscriptions[] = array(
				'product'	=> $row['gs_name'],
				'username'	=> $row['username'],
				'entity'	=> $this->container->get('stevotvr.groupsub.entity.subscription')->import($row),
			);
		}
		$this->db->sql_freeresult($result);

		return $subscriptions;
	}

	public function count_subscriptions()
	{
		$sql = 'SELECT COUNT(sub_id) AS sub_count
				FROM ' . $this->sub_table;
		$result = $this->db->sql_query($sql);
		$count = $this->db->sql_fetchfield('sub_count');
		$this->db->sql_freeresult($result);

		return (int) $count;
	}

	public function add_subscription(entity $subscription)
	{
		if (!function_exists('group_user_add'))
		{
			include $this->root_path . 'includes/functions_user.' . $this->php_ext;
		}

		$subscription->insert();
		$subscription_id = $subscription->get_id();
		$subscription->load($subscription_id);

		if ($subscription->get_id())
		{
			$user = $subscription->get_user();
			$groups = $this->prod_operator->get_groups($subscription->get_product());
			foreach ($groups as $group)
			{
				group_user_add($group, $user);
			}
		}

		return $subscription;
	}

	public function delete_subscription($sub_id)
	{
		$sql = 'DELETE FROM ' . $this->sub_table . '
				WHERE sub_id = ' . (int) $sub_id;
		$this->db->sql_query($sql);

		return (bool) $this->db->sql_affectedrows();
	}

	public function get_subscribed_users($group_id)
	{
		$ids = array();

		$sql_ary = array(
			'SELECT'	=> 's.user_id',
			'FROM'		=> array($this->group_table => 'g'),
			'LEFT_JOIN'	=> array(
				array(
					'FROM'	=> array($this->sub_table => 's'),
					'ON'	=> 'g.gs_id = s.gs_id',
				),
			),
			'WHERE'		=> 'g.group_id = ' . (int) $group_id . ' AND s.sub_expires > ' . (time() - $this->grace),
		);
		$sql = $this->db->sql_build_query('SELECT', $sql_ary);
		$result = $this->db->sql_query($sql);
		while ($row = $this->db->sql_fetchrow($result))
		{
			$ids[] = (int) $row['user_id'];
		}
		$this->db->sql_freeresult($result);

		return $ids;
	}

}
