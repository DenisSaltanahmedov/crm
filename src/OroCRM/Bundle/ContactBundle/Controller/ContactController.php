<?php

namespace OroCRM\Bundle\ContactBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBag;
use Symfony\Component\Form\Form;
use Symfony\Component\HttpFoundation\Response;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Oro\Bundle\UserBundle\Annotation\Acl;
use Oro\Bundle\UserBundle\Annotation\AclAncestor;

use Oro\Bundle\SoapBundle\Entity\Manager\ApiEntityManager;
use OroCRM\Bundle\ContactBundle\Entity\Contact;
use OroCRM\Bundle\ContactBundle\Datagrid\ContactDatagridManager;
use OroCRM\Bundle\ContactBundle\Datagrid\ContactAccountDatagridManager;
use OroCRM\Bundle\ContactBundle\Datagrid\ContactAccountUpdateDatagridManager;
use OroCRM\Bundle\AccountBundle\Entity\Account;

/**
 * @Acl(
 *      id="orocrm_contact",
 *      name="Contact manipulation",
 *      description="Contact manipulation",
 *      parent="root"
 * )
 */
class ContactController extends Controller
{
    /**
     * @Route("/view/{id}", name="orocrm_contact_view", requirements={"id"="\d+"})
     * @Template
     * @Acl(
     *      id="orocrm_contact_view",
     *      name="View Contact",
     *      description="View contact",
     *      parent="orocrm_contact"
     * )
     */
    public function viewAction(Contact $contact)
    {
        /** @var $accountDatagridManager ContactAccountDatagridManager */
        $accountDatagridManager = $this->get('orocrm_contact.account.view_datagrid_manager');
        $accountDatagridManager->setContact($contact);
        $datagridView = $accountDatagridManager->getDatagrid()->createView();

        if ('json' == $this->getRequest()->getRequestFormat()) {
            return $this->get('oro_grid.renderer')->renderResultsJsonResponse($datagridView);
        }

        return array(
            'entity'   => $contact,
            'datagrid' => $datagridView,
        );
    }

    /**
     * @Route("/info/{id}", name="orocrm_contact_info", requirements={"id"="\d+"})
     * @Template
     * @Acl(
     *      id="orocrm_contact_info",
     *      name="View Contact Info",
     *      description="View contact info",
     *      parent="orocrm_contact_view"
     * )
     */
    public function infoAction(Contact $contact)
    {
        return array(
            'entity' => $contact
        );
    }

    /**
     * @param Contact $entity
     * @return array
     */
    protected function processUpdate(Contact $entity = null)
    {
        if (!$entity) {
            $entity = $this->getManager()->createEntity();
        }

        /** @var $accountDatagridManager ContactAccountUpdateDatagridManager */
        $accountDatagridManager = $this->get('orocrm_contact.account.update_datagrid_manager');
        $accountDatagridManager->setContact($entity);
        $datagridView = $accountDatagridManager->getDatagrid()->createView();

        if ('json' == $this->getRequest()->getRequestFormat()) {
            return $this->get('oro_grid.renderer')->renderResultsJsonResponse($datagridView);
        }

        if ($this->get('orocrm_contact.form.handler.contact')->process($entity)) {
            $this->getFlashBag()->add('success', 'Contact successfully saved');

            return $this->get('oro_ui.router')->actionRedirect(
                array(
                    'route' => 'orocrm_contact_update',
                    'parameters' => array('id' => $entity->getId()),
                ),
                array(
                    'route' => 'orocrm_contact_index',
                )
            );
        }

        return array(
            'entity'   => $entity,
            'form'     => $this->get('orocrm_contact.form.contact'),
            'datagrid' => $datagridView,
        );
    }

    /**
     * Create contact form
     *
     * @Route("/create", name="orocrm_contact_create")
     * @Template("OroCRMContactBundle:Contact:update.html.twig")
     * @Acl(
     *      id="orocrm_contact_create",
     *      name="Create Contact",
     *      description="Create contact",
     *      parent="orocrm_contact"
     * )
     */
    public function createAction()
    {
        // add predefined account to contact
        $contact = null;
        $accountId = $this->getRequest()->get('account');
        if ($accountId) {
            /** @var Account $account */
            $account = $this->getDoctrine()->getRepository('OroCRMAccountBundle:Account')->find($accountId);
            if ($account) {
                /** @var Contact $contact */
                $contact = $this->getManager()->createEntity();
                $contact->addAccount($account);
            }
        }

        $response = $this->processUpdate($contact);
        if ($response instanceof Response) {
            return $response;
        }

        // set predefined accounts data
        /** @var Form $form */
        $form = $response['form'];
        if ($contact) {
            $form->get('appendAccounts')->setData($contact->getAccounts());
        }
        $response['form'] = $form->createView();

        return $response;
    }

    /**
     * Update user form
     *
     * @Route("/update/{id}", name="orocrm_contact_update", requirements={"id"="\d+"}, defaults={"id"=0})
     * @Template
     * @Acl(
     *      id="orocrm_contact_update",
     *      name="Update Contact",
     *      description="Update contact",
     *      parent="orocrm_contact"
     * )
     */
    public function updateAction(Contact $entity = null)
    {
        $response = $this->processUpdate($entity);
        if ($response instanceof Response) {
            return $response;
        }

        /** @var Form $form */
        $form = $response['form'];
        $response['form'] = $form->createView();

        return $response;
    }

    /**
     * @Route(
     *      "/{_format}",
     *      name="orocrm_contact_index",
     *      requirements={"_format"="html|json"},
     *      defaults={"_format" = "html"}
     * )
     * @Template
     * @Acl(
     *      id="orocrm_contact_list",
     *      name="View List of Contacts",
     *      description="View list of contacts",
     *      parent="orocrm_contact"
     * )
     */
    public function indexAction()
    {
        /** @var $gridManager ContactDatagridManager */
        $gridManager = $this->get('orocrm_contact.contact.datagrid_manager');
        $datagridView = $gridManager->getDatagrid()->createView();

        if ('json' == $this->getRequest()->getRequestFormat()) {
            return $this->get('oro_grid.renderer')->renderResultsJsonResponse($datagridView);
        }

        return array('datagrid' => $datagridView);
    }

    /**
     * @return FlashBag
     */
    protected function getFlashBag()
    {
        return $this->get('session')->getFlashBag();
    }

    /**
     * @return ApiEntityManager
     */
    protected function getManager()
    {
        return $this->get('orocrm_contact.contact.manager');
    }
}
