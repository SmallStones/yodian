<?php if ( ! defined('IN_DILICMS')) exit('No direct script access allowed');
/**
 * DiliCMS
 *
 * 一款基于并面向CodeIgniter开发者的开源轻型后端内容管理系统.
 *
 * @package     DiliCMS
 * @author      DiliCMS Team
 * @copyright   Copyright (c) 2011 - 2012, DiliCMS Team.
 * @license     http://www.dilicms.com/license
 * @link        http://www.dilicms.com
 * @since       Version 1.0
 * @filesource
 */

// ------------------------------------------------------------------------

/**
 * DiliCMS 内容模型内容管理控制器
 *
 * @package     DiliCMS
 * @subpackage  Controllers
 * @category    Controllers
 * @author      Jeongee
 * @link        http://www.dilicms.com
 */
class Content extends Admin_Controller
{
	/**
     * 构造函数
     *
     * @access  public
     * @return  void
     */
	public function __construct()
	{
		parent::__construct();	
	}
	
	// ------------------------------------------------------------------------
	
	/**
     * 默认入口(列表页)
     *
     * @access  public
     * @return  void
     */				
	public function view()
	{
		$this->_view_post();
	}
	
	// ------------------------------------------------------------------------
	
	/**
     * 内容列表页
     *
     * @access  public
     * @return  void
     */
	public function _view_post()
	{
		$model = $this->input->get('model', TRUE);
		if ( ! $model AND $this->acl->_default_link)
		{
			redirect($this->acl->_default_link);
		}
		$this->_check_permit();
		if ( ! $this->platform->cache_exists(DILICMS_SHARE_PATH . 'settings/model/' . $model . '.php'))
		{
			$this->_message('不存在的模型！', '', FALSE);
		}
		$this->plugin_manager->trigger_model_action('register_on_reach_model_list');
		$this->settings->load('model/' . $model);
		$data['model'] = $this->settings->item('models');
		$data['model'] = $data['model'][$model];
		$this->load->library('form');
		$this->load->library('field_behavior');
		$data['provider'] = $this->_pagination($data['model']);
		$this->_template('content_list', $data);	
	}
	
	// ------------------------------------------------------------------------
	
	/**
     * 分页处理
     *
     * @access  private
     * @param   array
     * @return  array
     */
	private function _pagination($model)
	{
		$this->load->library('pagination');
		$config['base_url'] = backend_url('content/view');
		$config['per_page'] = $model['perpage'];
		$config['uri_segment'] = 3;
		$config['suffix'] = '?model=' . $model['name'];
			
		$condition = array('id >' => '0');
		$data['where'] = array();
		
		foreach ($model['searchable'] as $v)
		{
			$this->field_behavior->on_do_search($model['fields'][$v], $condition, $data['where'], $config['suffix']);
		}
		
		$this->plugin_manager->trigger_model_action('register_before_query', $condition);
		
		$config['total_rows'] = $this->db->where($condition)->count_all_results('dili_u_m_' . $model['name']);
		
		$this->db->from('dili_u_m_' . $model['name']);
		$this->db->select('id');
		$this->db->where($condition);
		$this->field_behavior->set_extra_condition();
		foreach ($model['listable'] as $v)
		{
			$this->db->select($model['fields'][$v]['name']);	
		}
		
		$this->db->order_by('create_time', 'DESC');
		$this->db->offset($this->uri->segment($config['uri_segment'], 0));
		$this->db->limit($config['per_page']);
		
		$data['list'] = $this->db->get()->result();
		
		$this->plugin_manager->trigger_model_action('register_before_list', $data['list']);
		
		$config['first_url'] = $config['base_url'] . $config['suffix'];
		$this->pagination->initialize($config);
		$data['pagination'] = $this->pagination->create_links();
		return $data;
	}
	
	// ------------------------------------------------------------------------
	
	/**
     * 添加/修改入口
     *
     * @access  public
     * @return  void
     */
	public function form()
	{
		$this->_save_post();
	}
	
	// ------------------------------------------------------------------------
	
	/**
     * 添加/修改表单显示/处理函数
     *
     * @access  public
     * @return  void
     */
	public function _save_post()
	{
		
		$model = $this->input->get('model', TRUE);
		$this->settings->load('model/' . $model);
		$data['model'] = $this->settings->item('models');
		$data['model'] = $data['model'][$model];
		$id = $this->input->get('id');
		if ($id)
		{
			$this->_check_permit('edit');
			$data['content'] = $this->db->where('id',$id)->get('dili_u_m_' . $model)->row_array();
			$data['attachment'] = $this->db->where('model', $data['model']['id'])
										   ->where('content', $id)
										   ->where('from', 0)
										   ->get('dili_attachments')
										   ->result_array();
		}
		else
		{
			$this->_check_permit('add');
			$data['content'] = array();
		}
		
		$this->load->library('form_validation');
		
		foreach ($data['model']['fields'] as $v)
		{
			if ($v['rules'] != '')
			{
				$this->form_validation->set_rules($v['name'], $v['description'], str_replace(",", "|", $v['rules']));
			}
		}
		
		
		$this->load->library('form');
		$this->load->library('field_behavior');
		if ($this->form_validation->run() == FALSE)
		{
			$this->_template('content_form', $data);
		}
		else
		{
			$modeldata = $data['model'];
			$data = array();
			foreach ($modeldata['fields'] as $v)
			{
				if ($v['editable'])
				{
					$this->field_behavior->on_do_post($v, $data);
				}
				
			}
			$attachment = $this->input->post('uploadedfile', TRUE);
			if ($id)
			{
				$this->db->where('id', $id);
				$data['update_time'] = $this->session->_get_time();
				$this->plugin_manager->trigger_model_action('register_before_update', $data , $id);
				$this->db->update('dili_u_m_' . $model,$data);
				$this->plugin_manager->trigger_model_action('register_after_update', $data , $id);
				if ($attachment != '0')
				{
					$this->db->set('model', $modeldata['id'])
							 ->set('from', 0)
							 ->set('content', $id)
							 ->where('aid in (' . $attachment . ')')
							 ->update('dili_attachments');	
				}
				$this->_message('修改成功！', 'content/form', TRUE, '?model=' . $modeldata['name'] . '&id=' . $id);
			}
			else
			{
			    
				$data['create_time'] = $data['update_time'] = $this->session->_get_time();
				$this->plugin_manager->trigger_model_action('register_before_insert', $data);
				$this->db->insert('dili_u_m_' . $model,$data);
				$id = $this->db->insert_id();
				$this->plugin_manager->trigger_model_action('register_after_insert', $data,$id);
				if ($attachment != '0')
				{
					$this->db->set('model', $modeldata['id'])
							 ->set('from', 0)
							 ->set('content', $id)
							 ->where('aid in (' . $attachment . ')')
							 ->update('dili_attachments');	
				}
				$this->_message('添加成功！', 'content/view', TRUE, '?model=' . $modeldata['name']);	
			}
		}
		
	}
	
	// ------------------------------------------------------------------------
	
	/**
     * 删除入口
     *
     * @access  public
     * @return  void
     */
	public function del()
	{
		$this->_check_permit();
		$this->_del_post();	
	}
	
	// ------------------------------------------------------------------------
	
	/**
     * 删除处理函数
     *
     * @access  public
     * @return  void
     */
	public function _del_post()
	{
		$this->_check_permit();
		$ids = $this->input->get_post('id', TRUE);
		$model = $this->input->get('model', TRUE);
		$model_id = $this->db->select('id')->where('name', $model)->get('dili_models')->row()->id;
		if ($ids)
		{
			
			if ( ! is_array($ids))
			{
				$ids = array($ids);
			}
			$this->plugin_manager->trigger_model_action('register_before_delete', $ids);
			$attachments = $this->db->select('name, folder, type')
									->where('model', $model_id)
									->where('from', 0)
									->where_in('content', $ids)
									->get('dili_attachments')
									->result();
			foreach ($attachments as $attachment)
			{
				$this->platform->file_delete(DILICMS_SHARE_PATH . '../' . 
											 setting('attachment_dir') . '/' . 
											 $attachment->folder . '/' . 
											 $attachment->name . '.' . 
											 $attachment->type);		
			}
			$this->db->where('model', $model_id)->where_in('content', $ids)->where('from', 0)->delete('dili_attachments');
			$this->db->where_in('id', $ids)->delete('dili_u_m_' . $model);
			$this->plugin_manager->trigger_model_action('register_after_delete', $ids);
		}
		$this->_message('删除成功！', '', TRUE);	
	}
	
	// ------------------------------------------------------------------------
	
	/**
     * 相关附件列表和删除
     *
     * @access  public
     * @param   string
     * @return  void
     */
	public function attachment($action = 'list')
	{
		if ($action == 'list')
		{
			$response = array();
			$ids = $this->input->get('ids', TRUE);
			$attachments = 	$this->db->select('aid, realname, name, image, folder, type')
									 ->where("aid in ($ids)")
									 ->get('dili_attachments')
									 ->result_array();
			foreach ($attachments as $v)
			{
				array_push($response, implode('|', $v));	
			}
			echo implode(',', $response);
		}
		else if($action == 'del')
		{
			$attach = $this->db->select('aid, name, folder, type')
							   ->where('aid', $this->input->get('id', TRUE))
							   ->get('dili_attachments')
							   ->row(); 
			if ($attach)
			{
				$this->platform->file_delete(DILICMS_SHARE_PATH . '../' . 
											 setting('attachment_dir') . '/' . 
											 $attach->folder . '/' . 
											 $attach->name . '.' . 
											 $attach->type);		
				$this->db->where('aid', $attach->aid)->delete('dili_attachments');
				echo 'ok';
			}
		}
	}
	
	// ------------------------------------------------------------------------
	
}

/* End of file content.php */
/* Location: ./admin/controllers/content.php */
