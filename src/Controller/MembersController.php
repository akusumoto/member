<?php
namespace App\Controller;

use App\Controller\AppController;
use App\Model\Entity\Status;
use App\Model\Entity\Activity;
use App\Model\Entity\Part;
use App\Util\MembersUtil;
use App\Util\RedmineUtil;
use App\Util\PostfixUtil;
use Cake\Core\Configure;
use Cake\Log\Log;
use Cake\Network\Email\Email;

/**
 * Members Controller
 *
 * @property \App\Model\Table\MembersTable $Members
 */
class MembersController extends AppController
{

    public function initialize()
    {
        parent::initialize();
        $this->Auth->allow(['join', 'success', 'rule']);
    }

    public function rule()
    {
        $this->layout = 'public';
        $rule = $this->Settings->findByName('member.rule')->first()->value;
        $this->set('rule', $rule);
        $this->set('_serialize', ['rule']);
    }

    public function top()
    {
        $this->paginate = [
            'contain' => ['Parts', 'MemberTypes', 'Statuses']
        ];
        $this->set('members', 
            $this->paginate(
                $this->Members->find()
                              ->order(['Members.status_id' => 'ASC'])
                              ->order(['Members.part_id' => 'ASC'])
        ));
        $this->set('_serialize', ['members']);
    }

    /**
     * Detail method
     *
     * @param string|null $id Member id.
     * @return void
     * @throws \Cake\Network\Exception\NotFoundException When record not found.
     */
    public function detail($id = null)
    {
        $this->view($id);
    }

    private function checkAuth()
    {
        $password = $this->Settings->findByName('member.join.password')->first()->value;
        if (isset($this->request->data['password'])){
            if ($this->request->data['password'] === $password) {
                $this->set('hash', md5($password));
                return true;
            }
            else {
                return false;
            }
        }

        if (isset($this->request->data['hash'])) {
            if ($this->request->data['hash'] === md5($password)) {
                $this->set('hash', md5($password));
                return true;
            } else {
                return false;
            }
        }
    
        return false;
    }

    public function success()
    {
        $this->layout = 'public';
    }

    /**
     * page flow:
     *  join ... input member information
     *   |
     *  join_confirm ... confirm input
     *   |
     *  join_success ... thank you page
     */
    public function join()
    {
        $this->layout = 'public';
        $member = $this->Members->newEntity();
        Log::write('debug', $this->request->data);
        if ($this->request->is('post')) {
            // post
            //Log::write('debug', 'join: POST');
            if ($this->checkAuth()) {
                if (isset($this->request->data['action'])) {
                    // 3. save member info & success page [no 
                    $member = $this->Members->patchEntity($member, $this->request->data);
                    $member->status_id = $this->Members->Statuses->getActive()->id;

                    if ($this->request->data['action'] === 'confirm') {

                        // confirm input data
                        $member->part = $this->Members->Parts->get($member->part_id);
                        $member->member_type = $this->Members->MemberTypes->get($member->member_type_id);
                        $member->nickname_english = $this->request->data['nickname_english'];
                        $this->set(compact('member', 'parts', 'memberTypes', 'statuses'));
						$this->set('password2', $this->request->data['password2']);
						$this->set('password2_check', $this->request->data['password2_check']);
                        $this->set('_serialize', ['member']);

                        return $this->render('join_confirm');

                    } else if ($this->request->data['action'] === 'register') { 
                        // save input data
                        $member->account = MembersUtil::generateAccount(
                            $member->part_id,
                            $this->request->data['nickname_english']
                        );
                        if ($this->Members->save($member)) {
                            $this->Activities->saveJoin($member);
                            Log::write('info', 'member info was saved: id='.$member->id." nickname=".$member->nickname);

							$rm_user = RedmineUtil::createUser($member, $this->request->data['password2']);
							if(is_null($rm_user)){
								// TODO: notify someone?
							}
							else {
								// TODO: 「アカウント情報をユーザーに送信」→チェックしない

								$default_project_names = Configure::read('Redmine.project.defaults');
								foreach($default_project_names as $project_name){
									$rm_project = RedmineUtil::getProject($project_name);
									if(is_null($rm_project)){
										// TODO: notify someone?
										continue;
									}

									if(RedmineUtil::setProject($rm_user['id'], $rm_project['id']) == false){
										// TODO: notify someone?
									}
								}

								$rm_group = RedmineUtil::getGroup($member->part_id);
								if(RedmineUtil::setGroup($rm_user['id'], $rm_group['id']) == false){
									// TODO: notify someone?
								}
								if(RedmineUtil::hideMailAddress($rm_user['id']) == false){
									// TODO: notify someone?
								}
								if(RedmineUtil::setTimezone($rm_user['id'], 'Tokyo') == false){
									// TODO: notify someone?
								}
								if(RedmineUtil::setNoSelfNotified($rm_user['id']) == false){
									// TODO: notify someone?
								}
							}

                            $this->Flash->success(__('Thank you for your joining!'));

                            $this->sendAutoReply($member);
                            $this->sendStaffNotification($member);

                            return $this->redirect(['action' => 'success']);
                        
                        } else {
                            $this->Flash->error(__('Some items are invalid. Please fix wrong items and try again.'));
                        }
                    }
                    else {
                        // aciton=back
                    }
                }
                else {
                    // 2. member info input form page [no mode=join]
                    // do nothing
                }
            }
            else {
                // show password page (1)
                Log::write('info', 'Invalid password: '.$this->request->data['password']);
                $this->Flash->error(__('Invalid password'));
                $this->set(compact('member'));
                $this->set('_serialize', ['member']);

                return $this->render('join_auth');
            }
//            }
        }
        else {
            //Log::write('debug', 'join: NOT POST');
            // 1. password form page [no POST]
            // Do nothing (Request to input password)
            $this->set(compact('member'));
            $this->set('_serialize', ['member']);
            return $this->render('join_auth');
        }

        $parts = $this->Members->Parts->find('list', ['limit' => 200, 'order' => ['id']]);
        $memberTypes = $this->Members->MemberTypes->find('list', ['limit' => 200, 'order' => ['id']]);
        $statuses = $this->Members->Statuses->find('list', ['limit' => 200, 'order' => ['id']]);
        $this->set(compact('member', 'parts', 'memberTypes', 'statuses'));
        $this->set('_serialize', ['member']);
    }

    public function rejoin($id = null)
    {
        $this->request->allowMethod(['post', 'rejoin']);
        $member = $this->Members->get($id);
        $member->status_id = $this->Members->Statuses->getActive()->id;
        if ($this->Members->save($member)) {
            $this->Activities->saveReJoin($member);

            $msg = 'The member has been re-joined.';

			# emai of redmine may be changed by user.
			$output = PostfixUtil::unstopMail($member->email);
			$msg .= ' '.$output;
			
			# get redmine account information
			$rm_user = RedmineUtil::findUser($member->account);

			if(is_null($rm_user)){
				$msg .= ' Not found redmine account: '.$member->account;
			}
			else{
				if(strcmp($rm_user['mail'], $member->email) != 0){
					# emai of redmine may be changed by user.
					$output = PostfixUtil::unstopMail($rm_user['mail']);
					$msg .= ' '.$output;
				}

				if(RedmineUtil::enableNotification($rm_user['id'])){
					$msg .= ' Enableed mail notification.';
				}
				else{
					$msg .= ' Failed to enable mail notification.';
				}

				$rm_group = RedmineUtil::getGroup($member->part_id);
				if(RedmineUtil::setGroup($rm_user['id'], $rm_group['id'])){
					$msg .= ' set group '.$rm_group['name'].".";
				}
				else{
					$msg .= ' Failed to set group '.$rm_group['name'].".";
				}

				if(RedmineUtil::setNoSelfNotified($rm_user['id'])){
					// no message
				}
				else{
					$msg .= ' Failed to no_self_notified.';
				}

				if(RedmineUtil::unlockUser($rm_user['id'])){
					// no message
				}
				else{
					$msg .= ' Failed to unlock user.';
				}
			}

            $this->Flash->success($msg);
            return $this->redirect(['action' => 'detail', $id]);
        } else {
            $this->Flash->error('The member could not be change status. Please, try again.');
        }
    }

    public function leaveTemporary($id = null)
    {
        $this->request->allowMethod(['post', 'rest']);
        $member = $this->Members->get($id);
        $member->status_id = $this->Members->Statuses->getTempLeft()->id;
        if ($this->Members->save($member)) {
            $this->Activities->saveLeftTemporary($member);

			$msg = 'The member has been left temporary.';

			# emai of redmine may be changed by user.
			$output = PostfixUtil::stopMail($member->email);
			$msg .= ' '.$output;

			# get redmine account information
			$rm_user = RedmineUtil::findUser($member->account);
			if(is_null($rm_user)){
				$msg .= ' Not found redmine account('.$member->account.'), then plase change redmine setting manually.';
			}
			else{
				if(strcmp($rm_user['mail'], $member->email) != 0){
					# emai of redmine may be changed by user.
					$output = PostfixUtil::stopMail($rm_user['mail']);
					$msg .= ' '.$output;
				}

				if(RedmineUtil::disableNotification($rm_user['id'])){
					$msg .= ' Disabled mail notification.';
				}
				else{
					$msg .= ' Failed to disable mail notification.';
				}

				if(RedmineUtil::unsetAllGroups($rm_user['id'])){
					$msg .= ' Unset from all groups.';
				}
				else{
					$msg .= ' Failed to unset groups.';
				}
			}

            $this->Flash->success($msg);

            return $this->redirect(['action' => 'detail', $id]);
        } else {
            $this->Flash->error('The member could not be change status. Please, try again.');
        }
    }

    public function leave($id = null)
    {
        $this->request->allowMethod(['post', 'leave']);
        $member = $this->Members->get($id);
        $member->status_id = $this->Members->Statuses->getLeft()->id;
        if ($this->Members->save($member)) {
            $this->Activities->saveLeft($member);

            $msg = 'The member has been left.';

			# emai of redmine may be changed by user.
			$output = PostfixUtil::stopMail($member->email);
			$msg .= ' '.$output;

			# get redmine account information
			$rm_user = RedmineUtil::findUser($member->account);
			if(is_null($rm_user)){
				$msg .= ' Not found redmine account: '.$member->account;
			}
			else{
				if(strcmp($rm_user['mail'], $member->email) != 0){
					# emai of redmine may be changed by user.
					$output = PostfixUtil::stopMail($member->email);
					$msg .= ' '.$output;

				}

				if(RedmineUtil::disableNotification($rm_user['id'])){
					$msg .= ' Disabled mail notification.';
				}
				else{
					$msg .= ' Failed to disable mail notification.';
				}

				if(RedmineUtil::unsetAllGroups($rm_user['id'])){
					$msg .= ' Unset from all groups.';
				}
				else{
					$msg .= ' Failed to unset groups.';
				}

				if(RedmineUtil::unsetAllProjects($rm_user['id'])){
					$msg .= ' Unset from all projects.';
				}
				else{
					$msg .= ' Failed to unset some projects.';
				}

				if(RedmineUtil::lockUser($rm_user['id'])){
					$msg .= ' Locked the user.';
				}
				else{
					$msg .= ' Failed to lock the user.';
				}
			}

            $this->Flash->success($msg);
            return $this->redirect(['action' => 'detail', $id]);
        } else {
            $this->Flash->error('The member could not be change status. Please, try again.');
        }
    }

    public function update($id = null)
    {
        $member = $this->Members->get($id, [
            'contain' => []
        ]);

        if ($this->request->is(['patch', 'post', 'put'])) {
            $member = $this->Members->patchEntity($member, $this->request->data);
            if ($this->Members->save($member)) {
                $this->Flash->success('The member has been saved.');
                $this->Activities->saveUpdate($member);
                return $this->redirect(['action' => 'detail', $id]);
            } else {
                $this->Flash->error('The member could not be saved. Please, try again.');
            }
        }

        $parts = $this->Members->Parts->find('list', ['limit' => 200, 'order' => ['id']]);
        $memberTypes = $this->Members->MemberTypes->find('list', ['limit' => 200, 'order' => ['id']]);
        $statuses = $this->Members->Statuses->find('list', ['limit' => 200, 'order' => ['id']]);
        $this->set(compact('member', 'parts', 'sexes', 'bloods', 'memberTypes', 'statuses'));
        $this->set('_serialize', ['member']);
        
    }


    ////////////////////////////////////////////////////////////////////////
    //  /raw/members/
    ////////////////////////////////////////////////////////////////////////

    /**
     * Index method
     *
     * @return void
     */
    public function index()
    {
        $this->paginate = [
            'contain' => ['Parts', 'MemberTypes', 'Statuses']
        ];
        $this->set('members', $this->paginate($this->Members));
        $this->set('_serialize', ['members']);
    }

    /**
     * View method
     *
     * @param string|null $id Member id.
     * @return void
     * @throws \Cake\Network\Exception\NotFoundException When record not found.
     */
    public function view($id = null)
    {
        $member = $this->Members->get($id, [
            'contain' => ['Parts', 'MemberTypes', 'Statuses', 'Activities', 'MemberHistories']
        ]);

		if(PostfixUtil::isStopped($member->email)){
			$member->email .= ' (BLOCKED)';
		}

		$rm_user = RedmineUtil::findUser($member->account);
		if(is_null($rm_user) == false){
			if(PostfixUtil::isStopped($rm_user['mail'])){
				$rm_user['mail'] .= ' (BLOCKED)';
			}
        	$this->set('rm_user', $rm_user);
		}

        $this->set('member', $member);
        $this->set('_serialize', ['member', 'rm_user']);
    }

    /**
     * Add method
     *
     * @return void Redirects on successful add, renders view otherwise.
     */
    public function add()
    {
        $member = $this->Members->newEntity();
        if ($this->request->is('post')) {
            $member = $this->Members->patchEntity($member, $this->request->data);
            if ($this->Members->save($member)) {
                $this->Flash->success('The member has been saved.');
                return $this->redirect(['action' => 'index']);
            } else {
                $this->Flash->error('The member could not be saved. Please, try again.');
            }
        }
        $parts = $this->Members->Parts->find('list', ['limit' => 200, 'order' => ['id']]);
        $memberTypes = $this->Members->MemberTypes->find('list', ['limit' => 200, 'order' => ['id']]);
        $statuses = $this->Members->Statuses->find('list', ['limit' => 200, 'order' => ['id']]);
        $this->set(compact('member', 'parts', 'memberTypes', 'statuses'));
        $this->set('_serialize', ['member']);
    }

    /**
     * Edit method
     *
     * @param string|null $id Member id.
     * @return void Redirects on successful edit, renders view otherwise.
     * @throws \Cake\Network\Exception\NotFoundException When record not found.
     */
    public function edit($id = null)
    {
        $member = $this->Members->get($id, [
            'contain' => []
        ]);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $member = $this->Members->patchEntity($member, $this->request->data);
            if ($this->Members->save($member)) {
                $this->Flash->success('The member has been saved.');
                return $this->redirect(['action' => 'index']);
            } else {
                $this->Flash->error('The member could not be saved. Please, try again.');
            }
        }
        $parts = $this->Members->Parts->find('list', ['limit' => 200, 'order' => ['id']]);
        $memberTypes = $this->Members->MemberTypes->find('list', ['limit' => 200, 'order' => ['id']]);
        $statuses = $this->Members->Statuses->find('list', ['limit' => 200, 'order' => ['id']]);
        $this->set(compact('member', 'parts', 'memberTypes', 'statuses'));
        $this->set('_serialize', ['member']);
    }

    /**
     * Delete method
     *
     * @param string|null $id Member id.
     * @return void Redirects to index.
     * @throws \Cake\Network\Exception\NotFoundException When record not found.
     */
    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $member = $this->Members->get($id);

        if ($this->Members->delete($member)) {
            $this->Flash->success('The member has been deleted.');
        } else {
            $this->Flash->error('The member could not be deleted. Please, try again.');
        }
        return $this->redirect(['action' => 'index']);
    }


    private function buildText($body, $member)
    {
        $body = $this->replaceTag($body, $member);

        $lines = explode("\n", $body);
        $buf = "";
        $built_body = "";
        foreach($lines as $line){
            $line = trim($line);
            $line = $this->replaceWord('(https?:\/\/[0-9a-zA-Z\-_\.\!\*\'\(\)]+)', '<a href="$1">$1</a>', $line);

            if(strlen($buf) == 0){
                if(strlen($line) > 0){
                    $buf = $line ."\n";
                } 
            }
            else{
                if(strlen($line) > 0){
                    $buf .= "<br>".$line ."\n";
                }
                else{
                    $built_body .= "<p>".$buf."</p>";
                    $buf = "";
                }
            } 
        }
        if(strlen($buf) > 0){
            $built_body .= "<p>".$buf."</p>";
        }

        return $built_body;
    }

    private function replaceWord($reg, $ptn, $txt)
    {
        $txt = preg_replace('/ '.$reg.' /', ' '.$ptn.' ', $txt);
        $txt = preg_replace('/^'.$reg.' /',     $ptn.' ', $txt);
        $txt = preg_replace('/ '.$reg.'$/', ' '.$ptn,     $txt);
        $txt = preg_replace('/^'.$reg.'$/',     $ptn,     $txt);

        return $txt;
    }

    private function replaceTag($text, $member)
    {
        $text = str_replace('((#part#))', $this->Members->Parts->get($member->part_id)->name, $text);
        $text = str_replace('((#nickname#))', $member->nickname, $text);
        $text = str_replace('((#name#))', $member->name, $text);
        $text = str_replace('((#account#))', $member->account, $text);
        $text = str_replace('((#phone#))', $member->phone, $text);
        $text = str_replace('((#email#))', $member->email, $text);
        $text = str_replace('((#home_address#))', $member->home_address, $text);
        $text = str_replace('((#work_address#))', $member->work_address, $text);
        $text = str_replace('((#member_type#))', $this->Members->MemberTypes->get($member->member_type_id)->name, $text);
        $text = str_replace('((#emergency_phone#))', $member->emergency_phone, $text);
        $text = str_replace('((#note#))', $member->note, $text);

        return $text;
    }

    private function buildMailSubject($subject, $member)
    {
        return $this->replaceTag($subject, $member);
    }

    private function buildMailBody($body, $member)
    {
        return $this->replaceTag($body, $member);
    }


    private function strToMailArray($str)
    {
        $email_list = array();
        if (empty($str)) {
            return $email_list;
        }

        foreach(explode(',', $str) as $email){
            $email = trim($email);
            if(strlen($email) > 0){
                array_push($email_list, $email); 
            }
        }

        return $email_list;
    }

    private function sendAutoReply($member)
    {
        $from = $this->Settings->findByName('mail.full.from')->first()->value;
        //$tolist = $this->strToMailArray($this->Settings->findByName('mail.full.to')->first()->value);
        $cclist = $this->strToMailArray($this->Settings->findByName('mail.full.cc')->first()->value);
        $bcclist = $this->strToMailArray($this->Settings->findByName('mail.full.bcc')->first()->value);
        $subject = $this->Settings->findByName('mail.full.subject')->first()->value;
        $body = $this->Settings->findByName('mail.full.body')->first()->value;

        $email = new Email('default');
        //$email->sender($from)
        $email->to($member->email)
              ->cc($cclist)
              ->bcc($bcclist)
              ->subject($this->buildMailSubject($subject, $member))
              ->send($this->buildMailBody($body, $member));
                
    }

    private function sendStaffNotification($member)
    {
        $from = $this->Settings->findByName('mail.abst.from')->first()->value;
        $tolist = $this->strToMailArray($this->Settings->findByName('mail.abst.to')->first()->value);
        $cclist = $this->strToMailArray($this->Settings->findByName('mail.abst.cc')->first()->value);
        $bcclist = $this->strToMailArray($this->Settings->findByName('mail.abst.bcc')->first()->value);
        $subject = $this->Settings->findByName('mail.abst.subject')->first()->value;
        $body = $this->Settings->findByName('mail.abst.body')->first()->value;

        if(count($tolist) == 0){
            Log::write('warning', 'No email address to send notification mail. do nothing.');
            return;
        }

        $email = new Email('default');
        //$email->sender($from)
        $email->to($tolist)
              ->cc($cclist)
              ->bcc($bcclist)
              ->subject($this->buildMailSubject($subject, $member))
              ->send($this->buildMailBody($body, $member));
    }
}
