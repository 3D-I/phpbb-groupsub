<?php
/**
 *
 * Group Subscription. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2017, Steve Guidetti, https://github.com/stevotvr
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace stevotvr\groupsub\controller;

use phpbb\json_response;
use phpbb\pagination;
use phpbb\user;
use stevotvr\groupsub\entity\subscription_interface as sub_entity;
use stevotvr\groupsub\exception\base;
use stevotvr\groupsub\operator\package_interface as pkg_operator;
use stevotvr\groupsub\operator\subscription_interface as sub_operator;

/**
 * Group Subscription subscription management ACP controller.
 */
class acp_subs_controller extends acp_base_controller implements acp_subs_interface
{
	/**
	 * @var \stevotvr\groupsub\operator\package_interface
	 */
	protected $pkg_operator;

	/**
	 * @var \stevotvr\groupsub\operator\subscription_interface
	 */
	protected $sub_operator;

	/**
	 * @var \phpbb\user
	 */
	protected $user;

	/**
	 * @var \phpbb\pagination
	 */
	protected $pagination;

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
	 * Set up the controller.
	 *
	 * @param \stevotvr\groupsub\operator\package_interface      $pkg_operator
	 * @param \stevotvr\groupsub\operator\subscription_interface $sub_operator
	 * @param \phpbb\pagination                                  $pagination
	 * @param \phpbb\user                                        $user
	 */
	public function setup(pkg_operator $pkg_operator, sub_operator $sub_operator, pagination $pagination, user $user)
	{
		$this->pkg_operator = $pkg_operator;
		$this->sub_operator = $sub_operator;
		$this->pagination = $pagination;
		$this->user = $user;
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

	public function display()
	{
		$sort_key = $sort_dir = '';
		$start = $limit = $pkg_id = 0;
		$params = $this->parse_display_params($sort_key, $sort_dir, $start, $limit, $pkg_id);

		if ($this->request->is_set_post('sort') || $this->request->is_set_post('filter'))
		{
			redirect($this->u_action . $params);
			return;
		}

		$this->load_sort_options($sort_key, $sort_dir);

		$subscriptions = $this->sub_operator
							->set_limit($limit)
							->set_start($start)
							->set_sort($this->get_sort_field($sort_key), ($sort_dir === 'd'))
							->set_package($pkg_id)
							->get_subscriptions();

		foreach ($subscriptions as $subscription)
		{
			$entity = $subscription['entity'];
			$this->template->assign_block_vars('subscription', array(
				'SUB_USER'		=> $subscription['username'],
				'SUB_PACKAGE'	=> $subscription['package'],
				'SUB_EXPIRES'	=> $entity->get_expire() ? $this->user->format_date($entity->get_expire()) : 0,

				'U_MOVE_UP'		=> $this->u_action . $params . '&amp;action=move_up&amp;id=' . $entity->get_id(),
				'U_MOVE_DOWN'	=> $this->u_action . $params . '&amp;action=move_down&amp;id=' . $entity->get_id(),
				'U_EDIT'		=> $this->u_action . $params . '&amp;action=edit&amp;id=' . $entity->get_id(),
				'U_DELETE'		=> $this->u_action . $params . '&amp;action=delete&amp;id=' . $entity->get_id(),
			));
		}

		$pkg_count = $this->load_packages($pkg_id);
		$this->template->assign_vars(array(
			'LIMIT'	=> $limit,

			'U_ACTION'	=> $this->u_action . $params,
			'U_ADD_SUB'	=> $this->u_action . $params . '&amp;action=add',

			'S_SHOW_ADD'	=> (bool) $pkg_count,
		));

		$total = $this->sub_operator->count_subscriptions();
		$this->pagination->generate_template_pagination($this->u_action, 'pagination', 'start', $total, $limit, $start);
	}

	/**
	 * Load the sorting options into template variables.
	 *
	 * @param string $sort_key The current sort key value
	 * @param string $sort_dir The current sort direction value
	 */
	protected function load_sort_options($sort_key, $sort_dir)
	{
		$options = array(
			'u'	=> $this->language->lang('ACP_GROUPSUB_USER'),
			'p'	=> $this->language->lang('ACP_GROUPSUB_SUB'),
			'e'	=> $this->language->lang('ACP_GROUPSUB_EXPIRES'),
		);
		foreach ($options as $key => $name)
		{
			$this->template->assign_block_vars('sort_key', array(
				'KEY'	=> $key,
				'NAME'	=> $name,

				'S_SELECTED'	=> ($key === $sort_key),
			));
		}

		$options = array(
			'a'	=> $this->language->lang('ASCENDING'),
			'd'	=> $this->language->lang('DESCENDING'),
		);
		foreach ($options as $key => $name)
		{
			$this->template->assign_block_vars('sort_dir', array(
				'KEY'	=> $key,
				'NAME'	=> $name,

				'S_SELECTED'	=> ($key === $sort_dir),
			));
		}
	}

	/**
	 * Translate the sort key into the name of the database column.
	 *
	 * @param string $sort_key The sort key
	 *
	 * @return string The name of the database column
	 */
	protected function get_sort_field($sort_key)
	{
		switch ($sort_key)
		{
			case 'p':
				return 'p.pkg_name';
			break;
			case 'e':
				return 's.sub_expires';
			break;
		}

		return 'u.username';
	}

	public function add()
	{
		$params = $this->parse_display_params();
		$entity = $this->container->get('stevotvr.groupsub.entity.subscription');
		$this->add_edit_sub_data($entity, $params);

		$u_find_username = append_sid($this->root_path . 'memberlist.' . $this->php_ext,
			'mode=searchuser&amp;form=add_edit_sub&amp;field=sub_user&amp;select_single=true');
		$this->template->assign_vars(array(
			'S_ADD_SUB'	=> true,

			'U_ACTION'			=> $this->u_action . $params . '&amp;action=add',
			'U_FIND_USERNAME'	=> $u_find_username,
		));
	}

	public function edit($id)
	{
		$params = $this->parse_display_params();
		$subscription = $this->sub_operator->get_subscription($id);
		$this->add_edit_sub_data($subscription['entity'], $params);

		$this->template->assign_vars(array(
			'S_EDIT_SUB'	=> true,

			'SUB_PACKAGE'	=> $subscription['package'],
			'SUB_USER'		=> $subscription['username'],

			'U_ACTION'		=> $this->u_action . $params . '&amp;action=edit&amp;id=' . $id,
		));
	}

	/**
	 * Process data for the add/edit subscription form.
	 *
	 * @param \stevotvr\groupsub\entity\subscription_interface $entity The subscription
	 * @param string                                           $params The URL parameters string
	 */
	protected function add_edit_sub_data(sub_entity $entity, $params)
	{
		$errors = array();

		$submit = $this->request->is_set_post('submit');

		add_form_key('add_edit_sub');

		$data = array();
		if ($submit)
		{
			$this->parse_expire($data, $errors);
		}

		if ($entity->get_id())
		{
			$this->template->assign_vars(array(
				'SUB_PACKAGE'	=> $entity->get_package(),
				'SUB_USER'		=> $entity->get_user(),
			));
		}
		else
		{
			if ($submit)
			{
				$this->parse_username($data, $errors);
			}
			$data['package'] = $this->request->variable('sub_package', 0);

			if (!$this->load_packages($data['package']))
			{
				trigger_error($this->language->lang('ACP_GROUPSUB_ERROR_NO_PKGS') . adm_back_link($this->u_action . $params), E_USER_WARNING);
			}
		}

		if ($submit)
		{
			if (!check_form_key('add_edit_sub'))
			{
				$errors[] = 'FORM_INVALID';
			}

			foreach ($data as $name => $value)
			{
				try
				{
					$entity->{'set_' . $name}($value);
				}
				catch (base $e)
				{
					$errors[] = $e->get_message($this->language);
				}
			}

			if (empty($errors))
			{
				if ($entity->get_id())
				{
					$entity->save();
					$message = 'ACP_GROUPSUB_SUB_EDIT_SUCCESS';
				}
				else
				{
					$entity = $this->sub_operator->add_subscription($entity);
					$message = 'ACP_GROUPSUB_SUB_ADD_SUCCESS';
				}

				trigger_error($this->language->lang($message) . adm_back_link($this->u_action . $params));
			}
		}

		$errors = array_map(array($this->language, 'lang'), $errors);
		$expire = $entity->get_expire() ? $this->user->format_date($entity->get_expire(), 'Y-m-d') : '';

		$this->template->assign_vars(array(
			'S_ERROR'	=> (bool) count($errors),
			'ERROR_MSG'	=> count($errors) ? implode('<br />', $errors) : '',

			'SUB_EXPIRE'	=> $expire,

			'U_BACK'	=> $this->u_action . $params,
		));
	}

	/**
	 * Parse the user field for creating a subscription.
	 *
	 * @param array &$data   The submitted data
	 * @param array &$errors The error array
	 */
	protected function parse_username(array &$data, array &$errors)
	{
		$username = $this->request->variable('sub_user', '', true);
		$sql = 'SELECT user_id
				FROM ' . USERS_TABLE . "
				WHERE username_clean = '" . $this->db->sql_escape(utf8_clean_string($username)) . "'";
		$result = $this->db->sql_query($sql);
		$userrow = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$userrow)
		{
			$errors[] = 'NO_USER';
			return;
		}

		$data['user'] = (int) $userrow['user_id'];
	}

	/**
	 * Parse the expiration date fields.
	 *
	 * @param array &$data   The submitted data
	 * @param array &$errors The error array
	 */
	protected function parse_expire(array &$data, array &$errors)
	{
		$sub_expire = $this->request->variable('sub_expire', '');

		if ($sub_expire === '')
		{
			$data['expire'] = 0;
			return;
		}

		$date_parts = explode('-', $sub_expire);
		if (count($date_parts) == 3 && ((int) $date_parts[0] < 9999) &&
			(strlen($date_parts[0]) == 4) && (strlen($date_parts[1]) == 2) && (strlen($date_parts[2]) == 2))
		{
			$data['expire'] = $this->user->create_datetime()
										->setDate((int) $date_parts[0], (int) $date_parts[1], (int) $date_parts[2])
										->setTime(0, 0, 0)
										->getTimestamp();

			if ($data['expire'] < time())
			{
				$errors[] = 'ACP_GROUPSUB_ERROR_DATE_IN_PAST';
			}

			return;
		}

		$errors[] = 'ACP_GROUPSUB_ERROR_INVALID_DATE';
	}

	public function delete($id)
	{
		$sort_key = $sort_dir = '';
		$start = $limit = $pkg_id = 0;
		$params = $this->parse_display_params($sort_key, $sort_dir, $start, $limit, $pkg_id);

		if (!confirm_box(true))
		{
			$hidden_fields = build_hidden_fields(array(
				'id'		=> $id,
				'mode'		=> 'subscriptions',
				'sk'		=> $sort_key,
				'sd'		=> $sort_dir,
				'start'		=> $start,
				'limit'		=> $limit,
				'pkg_id'	=> $pkg_id,
				'action'	=> 'delete',
			));
			confirm_box(false, $this->language->lang('ACP_GROUPSUB_SUB_DELETE_CONFIRM'), $hidden_fields);
			return;
		}

		$this->sub_operator->delete_subscription($id);

		if ($this->request->is_ajax())
		{
			$json_response = new json_response();
			$json_response->send(array(
				'MESSAGE_TITLE'	=> $this->language->lang('INFORMATION'),
				'MESSAGE_TEXT'	=> $this->language->lang('ACP_GROUPSUB_SUB_DELETE_SUCCESS'),
				'REFRESH_DATA'	=> array(
					'time'	=> 3
				),
			));
		}

		trigger_error($this->language->lang('ACP_GROUPSUB_SUB_DELETE_SUCCESS') . adm_back_link($this->u_action . $params));
	}

	/**
	 * Parse the URL parameters for the main list display options.
	 *
	 * @param string &$sort_key   Variable to hold the value of the sort key parameters
	 * @param string &$sort_dir   Variable to hold the value of the sort direction parameters
	 * @param int    &$start      Variable to hold the value of the start parameters
	 * @param int    &$limit      Variable to hold the value of the limit parameters
	 * @param int    &$package_id Variable to hold the value of the package parameters
	 *
	 * @return string The reconstructed parameter string
	 */
	protected function parse_display_params(&$sort_key = '', &$sort_dir = '', &$start = 0, &$limit = 0, &$package_id = 0)
	{
		$sort_key = $this->request->variable('sk', 'u');
		$sort_dir = $this->request->variable('sd', 'a');
		$start = $this->request->variable('start', 0);
		$limit = min(100, $this->request->variable('limit', (int) $this->config['topics_per_page']));
		$package_id = $this->request->variable('pkg_id', 0);

		return sprintf(
			'&amp;sk=%s&amp;sd=%s&amp;start=%d&amp;limit=%d&amp;pkg_id=%d',
			$sort_key,
			$sort_dir,
			$start,
			$limit,
			$package_id
		);
	}

	/**
	 * Load the list of available packages into template block variables.
	 *
	 * @param int $selected The selected package ID
	 *
	 * @return int The number of packages
	 */
	protected function load_packages($selected = 0)
	{
		$entities = $this->pkg_operator->get_packages();

		foreach ($entities as $entity)
		{
			$s_selected = ($entity->get_id() === $selected);

			if ($s_selected)
			{
				$this->template->assign_vars(array(
					'PKG_NAME'	=> $entity->get_name(),
					'PKG_ID'	=> $selected,
				));
			}

			$this->template->assign_block_vars('package', array(
				'PKG_ID'	=> $entity->get_id(),
				'PKG_NAME'	=> $entity->get_name(),

				'S_SELECTED'	=> $s_selected,
			));
		}

		return count($entities);
	}
}
