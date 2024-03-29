<?php

namespace Drupal\sedm\Form\Templates\Curriculum\SubjectsTab;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\OpenModalDialogCommand;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\Logger\LoggerChannelTrait;

use Drupal\sedm\Database\CurriculumDatabaseOperations;

class AddNewSubjectForm extends FormBase {

      /**
       * {@inheritdoc}
       */
      public function getFormId() {
        return 'sedm_menu_curriculum_subjects_tab_add_new_subject';
      }

      /**
       * {@inheritdoc}
       */
      public function buildForm(array $form, FormStateInterface $form_state) {

          $form['#tree'] = TRUE;
          $form['form-container'] = [
            '#type' => 'container',
            '#prefix' => '<div id="add-subject-form-container-wrapper">',
            '#suffix' => '</div>',
          ];
    
          $form['form-container']['form-title'] = [
              '#type' => 'item',
              '#markup' => $this->t('<h2>Add New Subject</h2>'),
          ];
    
          $form['form-container']['subject-details-container'] = [
              '#type' => 'fieldset',
              '#title' => 'Subject Info.'
          ];
    
          $form['form-container']['subject-details-container']['description'] = [
              '#type' => 'item',
              '#markup' => $this->t('Fill out all the required details.'),
          ];
    
          /**
           * @RenderElement container : container is the wrapper 
           * of the college and department select render elements
           */
          $form['form-container']['subject-details-container']['select-container'] = [
              '#type' => 'container',
              '#prefix' => '<div id="subj-details-select-container-wrapper">',
              '#suffix' => '</div>',
          ];
    
          /**
           * @Variable $CDO = object to hold CurriculumDatabaseOperations class
           * @Variable $colleges = object to hold the result of the query
           * @Variable array $collegeOpt : holds the custom layout of every college
           *      for select render element
           */
          $CDO = new CurriculumDatabaseOperations(); // instantiate DatabaseOperations Class
          $colleges = $CDO->getColleges(); // get colleges
          $collegeOpt = array();
    
          foreach ($colleges as $college) {
    
            $collegeOpt[$college->college_uid] = $college->college_abbrev.' - '.$college->college_name;
    
          }
    
          /**
           * @RenderElement select: this element will trigger the 
           * selection of department
           */
          $form['form-container']['subject-details-container']['select-container']['college'] = [
            '#type' => 'select',
            '#title' => $this->t('College'),
            '#options' => $collegeOpt,
            '#required' => TRUE,
            '#attributes' => [
              'class' => ['flat-input',],
            ],
            '#weight' => 1,
          ];
    
          $subj_categories = $CDO->getSubjectCategories();
          $subj_cat_opt = array();
    
          foreach ($subj_categories as $subj_category){
            $subj_cat_opt[$subj_category->subjCat_uid] = $subj_category->subjCat_name;
          }
    
          $form['form-container']['subject-details-container']['select-container']['subj_category'] = [
            '#type' => 'select',
            '#title' => $this->t('Subject Category'),
            '#options' => $subj_cat_opt,
            '#required' => TRUE,
            '#attributes' => [
              'class' => ['flat-input',],
            ],
            '#weight' => 2,
          ];
    
        /**
         * @RenderElement container : this is the container of
         * all the render elements that are inline in style
         * ::subj-code
         * ::subj-units
         * ::subj-lecture-hrs
         * ::subj-lab-hrs
         */
          $form['form-container']['subject-details-container']['inline-container'] = [
              '#type' => 'container',
              '#attributes' => [
                'class' => ['inline-container-col2'],
              ],
          ];
    
          $form['form-container']['subject-details-container']['inline-container']['subj-code'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Subject Code'),
            '#maxlength' => 10,
            '#required' => TRUE,
            '#attributes' => [
              'placeholder' => 'Eng1',
              'class' => ['flat-input', ],
            ],
            '#weight' => 3,
          ];
    
          $form['form-container']['subject-details-container']['inline-container']['subj-description'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Subject Description'),
            '#maxlength' => 100,
            '#required' => TRUE,
            '#attributes' => [
                'class' => ['flat-input',],
                'placeholder' => 'Subject Description',
            ],
            '#weight' => 4,
          ];
    
          $form['form-container']['subject-details-container']['inline-container']['isActive'] = [
            '#type' => 'checkbox',
            '#title' => 'Set as Active',
            '#return_value' => 'active',
            '#weight' => 5,
          ];
    
          // Group submit handlers in an actions element with a key of "actions" so
          // that it gets styled correctly, and so that other modules may add actions
          // to the form.
          $form['form-container']['actions'] = [
            '#type' => 'actions',
          ];
    
          // Add a submit button that handles the submission of the form.
          $form['form-container']['actions']['submit'] = [
            '#type' => 'button',
            '#value' => $this->t('Submit'),
            '#ajax' => [
              'callback' => '::verifySubject',
              'wrapper' => 'add-subject-container-wrapper',
              'event' => 'click',
            ],
          ];

        return $form;
      }

      public function verifySubject(array &$form, FormStateInterface $form_state){

        $response = new AjaxResponse();

        if($form_state->getErrors()){

          $content['form-container']['notice-container']['status_messages'] = [
            '#type' => 'status_messages',
          ];

          $response->addCommand(new OpenDialogCommand('#notice-dialog',$this->t('Add New Subject'), $content, ['width' => '50%',]));

          return $response;

        }
        else {

          $subject['collegeUID'] = $form_state->getValue(['form-container','subject-details-container','select-container','college']);

          $subject['subjCat'] = $form_state->getValue(['form-container','subject-details-container','select-container','subj_category']);

          $subject['code'] = $form_state->getValue(['form-container','subject-details-container','inline-container','subj-code']);

          $subject['description'] = $form_state->getValue(['form-container','subject-details-container','inline-container','subj-description']);

          $subject['isActive'] = $form_state->getValue(['form-container','subject-details-container','inline-container','isActive']);

          $CDO = new CurriculumDatabaseOperations();
          $isSubjectAvailable = $CDO->isSubjectAvailable($subject['description'], null, $subject['subjCat']);
          // var_dump($isSubjectAvailable);
          if($isSubjectAvailable){
            
            $_SESSION['sedm']['subject'] = $subject; // final approach to be made
            $modal_form = \Drupal::formBuilder()->getForm('Drupal\sedm\Form\Modals\VerifySubjectModalForm', $subject);
            
          }
          else {

            $isSubjectAvailable = $CDO->isSubjectAvailable(null, $subject['code'], $subject['subjCat']);
            // var_dump($isSubjectAvailable);
            if($isSubjectAvailable){
              $_SESSION['sedm']['subject'] = $subject; // final approach to be made
              $modal_form = \Drupal::formBuilder()->getForm('Drupal\sedm\Form\Modals\VerifySubjectModalForm', $subject);
            }
            else {
              $modal_form['message'] = [
                '#type' => 'item',
                '#markup' => $this->t('Subject is already registered!'),
              ];
            }

          }

          $command = new OpenModalDialogCommand($this->t('Add new Subject'), $modal_form, ['width' => '50%']);

          $response->addCommand($command);

          return $response;


        } // end of else condition

      } // END OF verifySubject FUNCTION

      public function cancelAddingSubject(array &$form, FormStateInterface $form_state){

        $response = new AjaxResponse();

        $command = new CloseModalDialogCommand();

        $response->addCommand($command);

        return $response; 
      }

      /**
       * {@inheritdoc}
       */
      public function submitForm(array &$form, FormStateInterface $form_state) {

      }

}

?>

