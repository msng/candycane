<?php
class Issue extends AppModel
{
  var $name = 'Issue';
  var $actsAs = array(
    'ActivityProvider'=>
      array('find_options'=>
        array('include'=>array('Project', 
                                 'Author'=>array('className'=>'User','foreignKey'=>'author_id'),
                                 'Tracker'),
               'author_key'=>'author_id')
      ),
    'Watchable',
    'Customizable',
    'Attachable',
  );
#  acts_as_attachable :after_remove => :attachment_removed
#  acts_as_customizable
#  acts_as_watchable
#  acts_as_searchable :columns => ['subject', "#{table_name}.description", "#{Journal.table_name}.notes"],
#                     :include => [:project, :journals],
#                     # sort by id so that limited eager loading doesn't break with postgresql
#                     :order_column => "#{table_name}.id"
#  acts_as_event :title => Proc.new {|o| "#{o.tracker.name} ##{o.id}: #{o.subject}"},
#                :url => Proc.new {|o| {:controller => 'issues', :action => 'show', :id => o.id}}                
#  
#  acts_as_activity_provider :find_options => {:include => [:project, :author, :tracker]},
#                            :author_key => :author_id
  
  var $belongsTo = array(
    'Project',
    'Tracker',
    'Status' => array(
      'className'  => 'IssueStatus',
      'foreignKey' => 'status_id',
    ),
    'Author' => array(
      'className'  => 'User',
      'foreignKey' => 'author_id',
    ),
    'AssignedTo' => array(
      'className'  => 'User',
      'foreignKey' => 'assigned_to_id',
    ),
    'FixedVersion' => array(
      'className'  => 'Version',
      'foreignKey' => 'fixed_version_id',
    ),
    'Priority' => array(
      'className'  => 'Enumeration',
      'foreignKey' => 'priority_id',
    ),
    'Category' => array(
      'className'  => 'IssueCategory',
      'foreignKey' => 'category_id',
    ),
  );
  
  var $Journal = false;

#  has_many :journals, :as => :journalized, :dependent => :destroy
  var $hasMany = array(
    'TimeEntry'=>array('dependent'=>true),
  );
  var $hasAndBelongsToMany = array(
    'Changeset'=>array('order'=>"Changeset.committed_on ASC, Changeset.id ASC"),
  );
#  
#  has_many :relations_from, :class_name => 'IssueRelation', :foreign_key => 'issue_from_id', :dependent => :delete_all
#  has_many :relations_to, :class_name => 'IssueRelation', :foreign_key => 'issue_to_id', :dependent => :delete_all
#  
#  
#  validates_presence_of :subject, :priority, :project, :tracker, :author, :status
#  validates_length_of :subject, :maximum => 255
#  validates_inclusion_of :done_ratio, :in => 0..100
#  validates_numericality_of :estimated_hours, :allow_nil => true
#
#  def after_initialize
#    if new_record?
#      # set default values for new records only
#      self.status ||= IssueStatus.default
#      self.priority ||= Enumeration.default('IPRI')
#    end
#  end
#  
#  # Overrides Redmine::Acts::Customizable::InstanceMethods#available_custom_fields
#  def available_custom_fields
#    (project && tracker) ? project.all_issue_custom_fields.select {|c| tracker.custom_fields.include? c } : []
#  end
#  
  function copy_from($arg) {
    if(is_array($arg) && !empty($arg['Issue'])) {
      $issue = $arg;
    } elseif(is_string($arg)) {
      $issue = $this->find('first', array('conditions'=>array('id'=>$arg), 'recursive'=>-1));
    } else {
      $issue = false;
    }
    if(!empty($issue['Issue']['id'])) {
      unset($issue['Issue']['id']);
    }
    return $issue;
  }
#  
#  # Move an issue to a new project and tracker
  function move_to($Setting, $issue, $project_id, $tracker_id=false) {
    $db =& ConnectionManager::getDataSource($this->useDbConfig);
    $db->begin($this);
    if(!empty($project_id) && $issue['Issue']['project_id'] != $project_id) {
      # delete issue relations (because moveing to difference project is denied by Setting)
      if(empty($Setting->cross_project_issue_relations)) {
        $IssueRelation = & ClassRegistry::init('IssueRelation');
        if(!$IssueRelation->deleteAll(array('or'=>
          array('relations_from_id'=>$issue['Issue']['id']),
          array('relations_to_id'=>$issue['Issue']['id'])
        ))) {
          $db->rollback($this);
          return false;
        }
      }
      # issue is moved to another project
      # reassign to the category with same name if any
      $new_category = empty($issue['Issue']['category_id']) ? null : $this->Category->find('first', array('conditions'=>array('project_id'=>$project_id, 'name'=>$issue['Category']['name'])));
      $issue['Issue']['category_id'] = $new_category['Category']['id'];
      $issue['Category'] = $new_category['Category'];
      $issue['Issue']['fixed_version_id'] = null;
      $issue['Issue']['project_id'] = $project_id;
    }
    if(!empty($tracker_id)) {
      $issue['Issue']['tracker_id'] = $tracker_id;
    }
    if($this->save($issue)) {
      # Manually update project_id on related time entries
      $TimeEntry = & ClassRegistry::init('TimeEntry');
      if($TimeEntry->updateAll(array("project_id"=>$project_id), array('issue_id'=>$issue['Issue']['id']))) {
        $db->commit($this);
      } else {
        $db->rollback($this);
      }
    } else {
      $db->rollback($this);
    }
    return true;
  }
#  
#  def priority_id=(pid)
#    self.priority = nil
#    write_attribute(:priority_id, pid)
#  end
#  
#  def estimated_hours=(h)
#    write_attribute :estimated_hours, (h.is_a?(String) ? h.to_hours : h)
#  end
#  
#  def validate
#    if self.due_date.nil? && @attributes['due_date'] && !@attributes['due_date'].empty?
#      errors.add :due_date, :activerecord_error_not_a_date
#    end
#    
#    if self.due_date and self.start_date and self.due_date < self.start_date
#      errors.add :due_date, :activerecord_error_greater_than_start_date
#    end
#    
#    if start_date && soonest_start && start_date < soonest_start
#      errors.add :start_date, :activerecord_error_invalid
#    end
#  end
#  
#  def validate_on_create
#    errors.add :tracker_id, :activerecord_error_invalid unless project.trackers.include?(tracker)
#  end
#  
#  def before_create
#    # default assignment based on category
#    if assigned_to.nil? && category && category.assigned_to
#      self.assigned_to = category.assigned_to
#    end
#  end
#  
#  def before_save  
#    if @current_journal
#      # attributes changes
#      (Issue.column_names - %w(id description)).each {|c|
#        @current_journal.details << JournalDetail.new(:property => 'attr',
#                                                      :prop_key => c,
#                                                      :old_value => @issue_before_change.send(c),
#                                                      :value => send(c)) unless send(c)==@issue_before_change.send(c)
#      }
#      # custom fields changes
#      custom_values.each {|c|
#        next if (@custom_values_before_change[c.custom_field_id]==c.value ||
#                  (@custom_values_before_change[c.custom_field_id].blank? && c.value.blank?))
#        @current_journal.details << JournalDetail.new(:property => 'cf', 
#                                                      :prop_key => c.custom_field_id,
#                                                      :old_value => @custom_values_before_change[c.custom_field_id],
#                                                      :value => c.value)
#      }      
#      @current_journal.save
#    end
#    # Save the issue even if the journal is not saved (because empty)
#    true
#  end
  function beforeSave($options = array()) {
    parent::beforeSave($options);
    if(!$this->__exists) {
      if(empty($this->data['Issue']['assigned_to']) && !empty($this->data['Issue']['category_id'])) {
        $category = $this->Category->find('first', array('conditions'=>array('id'=>$this->data['Issue']['category_id']), 'recursive'=>-1));
        if(!empty($category['Category']['assigned_to_id'])) {
          $this->data['Issue']['assigned_to_id'] = $category['Category']['assigned_to_id'];
        }
      }
    }
    // empty must be null
    if(empty($this->data['Issue']['due_date'])) {
      unset($this->data['Issue']['due_date']);
    }
    if(empty($this->data['Issue']['start_date'])) {
      unset($this->data['Issue']['start_date']);
    }
    
    if($this->Journal) {
      # attributes changes
      $issue_column_names = array_keys($this->data['Issue']);
      $JournalDetail = & ClassRegistry::init('JournalDetail');
      $detail = false;
      foreach ($this->data['Issue'] as $c=>$v) {
        if(array_key_exists($c, array('id', 'description'))) {
          continue;
        }
        $detail = array('property' =>'attr', 'prop_key' =>$c);
        if($this->data['Issue'][$c] != $this->issue_before_change['Issue'][$c]) {
          $detail['old_value'] = $this->issue_before_change['Issue'][$c];
        }
      }
      # custom fields changes
      # TODO ジャーナルからカスタマイズビヘイビアの値は参照できるの？
/*
      $custom_values.each {|c|
        next if (@custom_values_before_change[c.custom_field_id]==c.value ||
                  (@custom_values_before_change[c.custom_field_id].blank? && c.value.blank?))
        @current_journal.details << JournalDetail.new(:property => 'cf', 
                                                      :prop_key => c.custom_field_id,
                                                      :old_value => @custom_values_before_change[c.custom_field_id],
                                                      :value => c.value)
      }      
*/
/* TODO ジャーナル保存
      $JournalDetail->create();
      @current_journal.save
*/
    }
    return true;
  }


#  
#  def after_save
#    # Reload is needed in order to get the right status
#    reload
#    
#    # Update start/due dates of following issues
#    relations_from.each(&:set_issue_to_dates)
#    
#    # Close duplicates if the issue was closed
#    if @issue_before_change && !@issue_before_change.closed? && self.closed?
#      duplicates.each do |duplicate|
#        # Reload is need in case the duplicate was updated by a previous duplicate
#        duplicate.reload
#        # Don't re-close it if it's already closed
#        next if duplicate.closed?
#        # Same user and notes
#        duplicate.init_journal(@current_journal.user, @current_journal.notes)
#        duplicate.update_attribute :status, self.status
#      end
#    end
#  end
#  
  var $issue_before_change = false;
  var $issue_before_change_status = false;
  var $custom_values_before_change = array();
  /**
   * @param : $issue  ex.$issue['Issue']['id']
   * @param : $user   ex.$user['id']
   * @param : $notes is any string
   */
  function init_journal($issue, $user, $notes = "") {
    if(empty($this->Journal)) {
      $this->Journal = & ClassRegistry::init('Journal');
      $this->Journal->create();
      $defaults = array(
        'journalized_id'=>$issue['Issue']['id'], 
        'user'=>$user['id'], 
        'notes' => $notes);
      $this->Journal->set($defaults);
    }
    $this->issue_before_change = $issue;
    $this->issue_before_change_status = $issue['Issue']['status_id'];
    $this->custom_values_before_change = array();
    // TODO : init_journal
#    self.custom_values.each {|c| @custom_values_before_change.store c.custom_field_id, c.value }
#    # Make sure updated_on is updated when adding a note.
#    updated_on_will_change!
#    @current_journal
    return $this->Journal;
  }
  function findValuesByJournalDetail($detail) {
    $label = '';
    $value = '';
    $old_value = '';
    $field_format = '';
    $attachment = false;

    switch($detail['property']) {
    case 'attr' :
      $options = array(
        'fields'=>array('name'),
        'conditions'=>array('id'=>0),
        'recursive'=>-1);
      foreach($this->belongsTo as $alias=>$assoc) {
        if($detail['prop_key'] == $assoc['foreignKey']) {
          if(!empty($detail['value'])) {
            $options['conditions']['id'] = $detail['value'];
            $result = $this->$alias->find('first', $options);
            $value = $result[$alias]['name'];
          }
          if(!empty($detail['old_value'])) {
            $options['conditions']['id'] = $detail['old_value'];
            $result = $this->$alias->find('first', $options);
            $old_value = $result[$alias]['name'];
          }
          break;
        }
      }
      break;
    case 'cf' :
      $custom_field = $this->findCustomFieldById($detail['prop_key']);
      if(!empty($custom_field)) {
        $label = $custom_field['CustomField']['name'];
        $field_format = $custom_field['CustomField']['field_format'];
      }
      break;
    case 'attachment' :
      $attachment = $this->findAttachableById($detail['prop_key']);
      break;
    }
    return compact('label', 'value', 'old_value', 'field_format', 'attachment');
  }
   
#  
#  # Return true if the issue is closed, otherwise false
#  def closed?
#    self.status.is_closed?
#  end
#  
#  # Returns true if the issue is overdue
#  def overdue?
#    !due_date.nil? && (due_date < Date.today)
#  end
#  
#  # Users the issue can be assigned to
#  def assignable_users
#    project.assignable_users
#  end
#  
#  # Returns an array of status that user is able to apply
#  def new_statuses_allowed_to(user)
#    statuses = status.find_new_statuses_allowed_to(user.role_for_project(project), tracker)
#    statuses << status unless statuses.empty?
#    statuses.uniq.sort
#  end
#  
#  # Returns the mail adresses of users that should be notified for the issue
#  def recipients
#    recipients = project.recipients
#    # Author and assignee are always notified unless they have been locked
#    recipients << author.mail if author && author.active?
#    recipients << assigned_to.mail if assigned_to && assigned_to.active?
#    recipients.compact.uniq
#  end
#  
  function spent_hours() {
    // Move to IssuesHelper
  }
#  
#  def relations
#    (relations_from + relations_to).sort
#  end
#  
#  def all_dependent_issues
#    dependencies = []
#    relations_from.each do |relation|
#      dependencies << relation.issue_to
#      dependencies += relation.issue_to.all_dependent_issues
#    end
#    dependencies
#  end
#  
#  # Returns an array of issues that duplicate this one
#  def duplicates
#    relations_to.select {|r| r.relation_type == IssueRelation::TYPE_DUPLICATES}.collect {|r| r.issue_from}
#  end
#  
#  # Returns the due date or the target due date if any
#  # Used on gantt chart
#  def due_before
#    due_date || (fixed_version ? fixed_version.effective_date : nil)
#  end
#  
#  def duration
#    (start_date && due_date) ? due_date - start_date : 0
#  end
#  
#  def soonest_start
#    @soonest_start ||= relations_to.collect{|relation| relation.successor_soonest_start}.compact.min
#  end
#  
#  def self.visible_by(usr)
#    with_scope(:find => { :conditions => Project.visible_by(usr) }) do
#      yield
#    end
#  end
#  
#  def to_s
#    "#{tracker} ##{id}: #{subject}"
#  end
#  
#  private
#  
#  # Callback on attachment deletion
#  def attachment_removed(obj)
#    journal = init_journal(User.current)
#    journal.details << JournalDetail.new(:property => 'attachment',
#                                         :prop_key => obj.id,
#                                         :old_value => obj.filename)
#    journal.save
#  end
#end
#
}