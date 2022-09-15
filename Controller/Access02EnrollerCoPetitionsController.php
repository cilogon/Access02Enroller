<?php

// This COmanage Registry enrollment plugin is intended to be used
// with an anonymous enrollment flow for ACCESS. At the end of
// the flow the user is required to set a password for their
// new ACCESS ID.
//
// The following enrollment steps are implemented:
//
// finalize:
//   - Used to add ACCESS ID ePPN as a login identifier
//     to the OrgIdentity created during the flow.
//
// petitionerAttributes:
//   - Checks the email address input by the user into
//     the form and if email address is already known
//     for a registered user then stops the flow and
//     redirects.
//   - Used to collect the ACCESS Organization for the
//     user from a controlled set.
//
// provision:
//   - Requires the user to set a Kerberos
//     password for their new ACCESS ID.
//
// TODO Ask user if want to add other email addresses?

App::uses('Access02Petition', 'Access02Enroller.Model');
App::uses('AccessOrganization', 'AccessOrganization.Model');
App::uses('CoPetitionsController', 'Controller');
App::uses('HtmlHelper', 'View/Helper');
App::uses('Krb', 'KrbAuthenticator.Model');
App::uses('KrbAuthenticator', 'KrbAuthenticator.Model');
 
class Access02EnrollerCoPetitionsController extends CoPetitionsController {
  // Class name, used by Cake
  public $name = "Access02EnrollerCoPetitions";
  public $uses = array("CoPetition");

  /**
   * Plugin functionality following finalize step
   *
   * @param Integer $id CO Petition ID
   * @param Array $onFinish URL, in Cake format
   */
  protected function execute_plugin_finalize($id, $onFinish) {
    $args = array();
    $args['conditions']['CoPetition.id'] = $id;
    $args['contain']['CoEnrollmentFlow'] = 'CoEnrollmentFlowFinMessageTemplate';
    $args['contain']['EnrolleeCoPerson'] = array('PrimaryName', 'Identifier');
    $args['contain']['EnrolleeCoPerson']['CoGroupMember'] = 'CoGroup';
    $args['contain']['EnrolleeCoPerson']['CoPersonRole'][] = 'Cou';
    $args['contain']['EnrolleeCoPerson']['CoPersonRole']['SponsorCoPerson'][] = 'PrimaryName';
    $args['contain']['EnrolleeOrgIdentity'] = array('EmailAddress', 'PrimaryName');

    $petition = $this->CoPetition->find('first', $args);
    $this->log("Access02Enroller Finalize: Petition is " . print_r($petition, true));

    $coId = $petition['CoPetition']['co_id'];
    $coPersonId = $petition['CoPetition']['enrollee_co_person_id'];

    // Find the ACCESS ID.
    $accessId = null;
    foreach($petition['EnrolleeCoPerson']['Identifier'] as $i) {
      if($i['type'] == 'accessid') {
        $accessId = $i['identifier'];
      }
    }

    if(!empty($accessId)) {
      // Attach an Identifier of type EPPN to the existing OrgIdentity and
      // mark it as a login identifier.
      $orgIdentityId = $petition['CoPetition']['enrollee_org_identity_id'];

      $this->CoPetition->EnrolleeOrgIdentity->Identifier->clear();

      $data = array();
      $data['Identifier']['identifier'] = $accessId . '@access-ci.org';
      $data['Identifier']['type'] = IdentifierEnum::ePPN;
      $data['Identifier']['status'] = SuspendableStatusEnum::Active;
      $data['Identifier']['login'] = true;
      $data['Identifier']['org_identity_id'] = $orgIdentityId;

      $this->CoPetition->EnrolleeOrgIdentity->Identifier->save($data);
    }

    // This step is completed so redirect to continue the flow.
    $this->redirect($onFinish);
  }

  /**
   * Plugin functionality following petitionerAttributes step
   *
   * @param Integer $id CO Petition ID
   * @param Array $onFinish URL, in Cake format
   */
   
  protected function execute_plugin_petitionerAttributes($id, $onFinish) {
    $args = array();
    $args['conditions']['CoPetition.id'] = $id;
    $args['contain']['EnrolleeCoPerson']['CoOrgIdentityLink']['OrgIdentity'] = 'EmailAddress';
    $args['contain']['EnrolleeCoPerson'][] = 'Identifier';
    $args['contain']['EnrolleeCoPerson'][] = 'Name';

    $petition = $this->CoPetition->find('first', $args);
    $this->log("Petitioner Attributes: Petition is " . print_r($petition, true));

    $coId = $petition['CoPetition']['co_id'];
    $coPersonId = $petition['CoPetition']['enrollee_co_person_id'];
    $coPersonRoleId = $petition['CoPetition']['enrollee_co_person_role_id'];
    $orgIdentityId = $petition['CoPetition']['enrollee_org_identity_id'];

    // Before continuing check the email address the anonymous user entered into
    // the form to see if this person was already registered and if so
    // redirect.
    $args = array();
    $args['conditions']['EmailAddress.mail'] = $petition['EnrolleeCoPerson']
                                                        ['CoOrgIdentityLink']
                                                        [0]
                                                        ['OrgIdentity']
                                                        ['EmailAddress']
                                                        [0]
                                                        ['mail'];
    $args['contain'] = false;

    $emailAddress = $this->CoPetition->EnrolleeCoPerson->EmailAddress->find('all', $args);

    if($emailAddress) {
      // Loop over the EmailAddress and exclude those attached
      // to the current petition.
      foreach($emailAddress as $e) {
        if($e['EmailAddress']['co_person_id'] != $coPersonId && $e['EmailAddress']['org_identity_id'] != $orgIdentityId) {
          $this->redirect("https://identity.access-ci.org/email-exists");
        }
      }
    }

    // Set the CoPetition ID to use as a hidden form element.
    $this->set('co_petition_id', $id);

    // Save the onFinish URL to which we must redirect after receiving
    // the incoming POST data.
    if(!$this->Session->check('access02.plugin.petitionerAttributes.onFinish')) {
      $this->Session->write('access02.plugin.petitionerAttributes.onFinish', $onFinish);
    }

    // Create an instance of the AccessOrganization model since we do 
    // not have a direct relationship with it.
    $accessOrganizationModel = new AccessOrganization();

    // Find the 'Other' organization and set its ID for the view.

    $args = array();
    $args['conditions']['AccessOrganization.name'] = "Other";
    $args['contain'] = false;

    $accessOther = $accessOrganizationModel->find('first', $args);

    $this->set('vv_access_organization_other_id', $accessOther['AccessOrganization']['id']);

    // Process incoming POST data.
    if($this->request->is('post')) {
      // Validate incoming data.
      $data = $this->validatePost();

      if(!$data) {
        // The call to validatePost() sets $this->Flash if there are any validation
        // errors so just return.
        return;
      }

      // Save the Access02 petition data.
      $petitionModel = new Access02Petition();
      $petitionModel->clear();

      $petitionData = array();
      $petitionData['Access02Petition']['co_petition_id'] = $this->data['Access02Petition']['co_petition_id'];
      $petitionData['Access02Petition']['access_organization_id'] = $this->data['Access02Petition']['access_organization_id'];

      if(!$petitionModel->save($petitionData)) {
        $this->log("Error saving Access02Petition data " . print_r($petitionData, true));
        $this->Flash->set(_txt('pl.access02_enroller.error.access02petition.save'), array('key' => 'error'));
        $this->redirect("/");
      }

      // Set the organization on the CO Person Role.
      $args = array();
      $args['conditions']['AccessOrganization.id'] = $this->data['Access02Petition']['access_organization_id'];
      $args['contain'] = false;

      $accessOrganization = $accessOrganizationModel->find('first', $args);
      $accessOrganizationName = $accessOrganization['AccessOrganization']['name'];

      $this->CoPetition->EnrolleeCoPersonRole->id = $coPersonRoleId;
      $this->CoPetition->EnrolleeCoPersonRole->saveField('o', $accessOrganizationName);

      $onFinish = $this->Session->consume('access02.plugin.petitionerAttributes.onFinish');

      // Done processing all POST data so redirect to continue enrollment flow.
      $this->redirect($onFinish);
    } // End of POST.
    
    // GET fall through to fiew.
  }

  /**
   * Plugin functionality following provision step
   *
   * @param Integer $id CO Petition ID
   * @param Array $onFinish URL, in Cake format
   */
   
  protected function execute_plugin_provision($id, $onFinish) {
    $args = array();
    $args['conditions']['CoPetition.id'] = $id;
    $args['contain']['EnrolleeCoPerson'][] = 'Identifier';

    $petition = $this->CoPetition->find('first', $args);
    $this->log("Provision: Petition is " . print_r($petition, true));

    $coId = $petition['CoPetition']['co_id'];
    $coPersonId = $petition['CoPetition']['enrollee_co_person_id'];

    // Find the ACCESS ID.
    $accessId = null;
    foreach($petition['EnrolleeCoPerson']['Identifier'] as $i) {
      if($i['type'] == 'accessid') {
        $accessId = $i['identifier'];
      }
    }

    // We assume that the CO has one and only one instantiated KrbAuthenticator
    // plugin and it is used for ACCESS ID password management.
    $args = array();
    $args['conditions']['Authenticator.co_id'] = $coId;
    $args['conditions']['Authenticator.plugin'] = 'KrbAuthenticator';
    $args['contain'] = false;

    $authenticator = $this->CoPetition->Co->Authenticator->find('first', $args);

    $args = array();
    $args['conditions']['KrbAuthenticator.authenticator_id'] = $authenticator['Authenticator']['id'];
    $args['contain'] = false;

    $krbAuthenticatorModel = new KrbAuthenticator();

    $krbAuthenticator = $krbAuthenticatorModel->find('first', $args);

    $cfg = array();
    $cfg['Authenticator'] = $authenticator['Authenticator'];
    $cfg['KrbAuthenticator'] = $krbAuthenticator['KrbAuthenticator'];
    $krbAuthenticatorModel->setConfig($cfg);

    // Set the CoPetition ID to use as a hidden form element.
    $this->set('co_petition_id', $id);

    $this->set('vv_authenticator', $krbAuthenticator);
    $this->set('vv_co_person_id', $coPersonId);
    $this->set('vv_access_id', $accessId);

    // Save the onFinish URL to which we must redirect after receiving
    // the incoming POST data.
    if(!$this->Session->check('access02.plugin.provision.onFinish')) {
      $this->Session->write('access02.plugin.provision.onFinish', $onFinish);
    }

    // Process incoming POST data.
    if($this->request->is('post')) {
      try {
        $krbAuthenticatorModel->manage($this->data, $coPersonId, true);
        $onFinish = $this->Session->consume('access02.plugin.provision.onFinish');
        $this->redirect($onFinish);
      } catch (Exception $e) {
        // Fall through to display the form again.
        $this->set('vv_efwid', $this->data['Krb']['co_enrollment_flow_wedge_id']);
        $this->Flash->set($e->getMessage(), array('key' => 'error'));
      }
    } // POST
    
    // GET fall through to view.
  }

  /**
   * Validate POST data from an add action.
   *
   * @return Array of validated data ready for saving or false if not validated.
   */

  private function validatePost() {
    $data = $this->request->data;

    // Validate the Access02Petition fields.
    $petitionModel = new Access02Petition();
    $petitionModel->clear();
    $petitionData = array();
    $petitionData['Access02Petition'] = $data['Access02Petition'];
    $petitionModel->set($data);

    $fields = array();
    $fields[] = 'co_petition_id';
    $fields[] = 'access_organization_id';

    $args = array();
    $args['fieldList'] = $fields;

    if(!$petitionModel->validates($args)) {
      $this->Flash->set(_txt('er.fields'), array('key' => 'error'));
      return false;
    }

    return $data;
  }
}
