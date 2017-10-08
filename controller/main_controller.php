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

use phpbb\config\config;
use phpbb\controller\helper;
use phpbb\language\language;
use phpbb\template\template;
use phpbb\user;
use stevotvr\groupsub\operator\product_interface;
use stevotvr\groupsub\operator\subscription_interface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Group Subscription controller for the main user-facing interface.
 */
class main_controller
{
	/**
	 * @var \phpbb\config\config
	 */
	protected $config;

	/**
	 * @var \Symfony\Component\DependencyInjection\ContainerInterface
	 */
	protected $container;

	/**
	 * @var \phpbb\controller\helper
	 */
	protected $helper;

	/**
	 * @var \phpbb\language\language
	 */
	protected $language;

	/**
	 * @var \stevotvr\groupsub\operator\product_interface
	 */
	protected $prod_operator;

	/**
	 * @var \stevotvr\groupsub\operator\subscription_interface
	 */
	protected $sub_operator;

	/**
	 * @var \phpbb\template\template
	 */
	protected $template;

	/**
	 * @var \phpbb\user
	 */
	protected $user;

	/**
	 * @param \phpbb\config\config                               $config
	 * @param ContainerInterface                                 $container
	 * @param \phpbb\controller\helper                           $helper
	 * @param \phpbb\language\language                           $language
	 * @param \stevotvr\groupsub\operator\product_interface      $prod_operator
	 * @param \stevotvr\groupsub\operator\subscription_interface $sub_operator
	 * @param \phpbb\template\template                           $template
	 * @param \phpbb\user                                        $user
	 */
	public function __construct(config $config, ContainerInterface $container, helper $helper, language $language, product_interface $prod_operator, subscription_interface $sub_operator, template $template, user $user)
	{
		$this->config = $config;
		$this->container = $container;
		$this->helper = $helper;
		$this->language = $language;
		$this->prod_operator = $prod_operator;
		$this->sub_operator = $sub_operator;
		$this->template = $template;
		$this->user = $user;

		$language->add_lang('common', 'stevotvr/groupsub');
	}

	/**
	 * Handle the /groupsub/{name} route.
	 *
	 * @param string|null $name The unique identifier of a product
	 *
	 * @return \Symfony\Component\HttpFoundation\Response A Symfony Response object
	 */
	public function handle($name)
	{
		$u_board = generate_board_url(true);
		$sandbox = $this->config['stevotvr_groupsub_pp_sandbox'];
		$business = $this->config[$sandbox ? 'stevotvr_groupsub_pp_sb_business' : 'stevotvr_groupsub_pp_business'];
		$this->template->assign_vars(array(
			'S_PP_SANDBOX'	=> $sandbox,

			'USER_ID'		=> $this->user->data['user_id'],
			'PP_BUSINESS'	=> $business,

			'U_NOTIFY'			=> $u_board . $this->helper->route('stevotvr_groupsub_ipn'),
			'U_CANCEL_RETURN'	=> $u_board . $this->helper->route('stevotvr_groupsub_main'),
		));

		$products = $this->prod_operator->get_products();
		foreach ($products as $product)
		{
			$this->template->assign_block_vars('product', array(
				'PROD_ID'		=> $product->get_id(),
				'PROD_NAME'		=> $product->get_name(),
				'PROD_DESC'		=> $product->get_desc_for_display(),
				'PROD_PRICE'	=> $product->get_price(),
				'PROD_CURRENCY'	=> $product->get_currency(),

				'U_RETURN'	=> $u_board . $this->helper->route('stevotvr_groupsub_main', array('name' => $product->get_ident())),
			));
		}

		return $this->helper->render('product_list.html', $this->language->lang('GROUPSUB_PRODUCT_LIST'));
	}
}