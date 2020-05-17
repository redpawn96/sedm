<?php

namespace Drupal\sedm\Database;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\Logger\LoggerChannelTrait;

use Drupal\sedm\Database\DatabaseOperations;

class EvaluationDatabaseOperations extends DatabaseOperations {
    use LoggerChannelTrait;

    public function getActiveSubjects($college){
        //setting up test_drupal_data database into active connection
        Database::setActiveConnection('test_drupal_data');
        // get the active connection and put into an object
        $connection = Database::getConnection();

        /**
         * Example Query
         * $query = $database->query("SELECT id, example FROM {mytable} WHERE created > :created", [
         *      ':created' => REQUEST_TIME - 3600,
         *    ]);
         */

        $query = $connection->query('SELECT *
        FROM {subjects, curriculum_subjects, curriculums, programs}
        WHERE programs.program_uid = curriculums.program_uid
        AND curriculums.curriculum_uid = curriculum_subjects.curriculum_uid
        AND curriculum_subjects.subject_uid = subjects.subject_uid
        AND subjects.subject_isActive = :active
        AND subjects.college_uid = :college_uid', [
            ':active' => 'active',
            ':college_uid' => $college,
        ]);
        $result = $query->fetchAll();

        Database::closeConnection();

        return $result;
    }

    public function getStudentInfo($id_number){
        //setting up test_drupal_data database into active connection
        Database::setActiveConnection('test_drupal_data');
        // get the active connection and put into an object
        $connection = Database::getConnection();

        $query = $connection->query('SELECT *
        FROM students, student_profile, programs, colleges
        WHERE students.student_uid = student_profile.student_uid
        AND students.program_uid = programs.program_uid
        AND programs.college_uid = colleges.college_uid
        AND students.student_schoolId = :student_id', 
        [
            ':student_id' => $id_number,
        ]);

        $result = $query->fetchAll();

        Database::closeConnection();

        return $result;

    }

    /**
     * @param $data : includes $data['id_number'], $data['year_level'], $data['semester']
     * @param $curri_uid : curriculum unique id
     */
    public function getAvailableSubjects($data, $curri_uid){
        //setting up test_drupal_data database into active connection
        Database::setActiveConnection('test_drupal_data');
        // get the active connection and put into an object
        $connection = Database::getConnection();

        $logger = $this->getLogger('sedm');
        $logger->info('getAvailableSubjects');

        $availableSubjs = array();

        // Algorithm:
        // #1: get the curriculum subjects of a certain year level and semester
        $curri_subjs = $this->getCurriculumSubjects($curri_uid, $data['year_level'], $data['semester']);
        // #2: for each subjects fetched will proceed to a 3 layer checking
        foreach($curri_subjs as $curri_subj){
            // #2.1: checking the subject using its code
            
            //BUG @STABLE RELEASE 1.1.0
            // passing id number on getEnrolledSubjectInfoByCode which it only accepts 
            $enrolledSubjInfo = $this->getEnrolledSubjectInfoByCode($data['id_number'], $curri_subj->subject_code);;
            // 2.1.1: if the subject is not found, proceed to checking the subject by description
            if(empty($enrolledSubjInfo)){
                // #2.2: checking the subject using its description
                $enrolledSubjInfo = $this->getEnrolledSubjectInfoByDesc($data['id_number'], $curri_subj->subject_desc);
                // #2.2.1: if the subject is not found.. proceed to the next checking the prerequisite satifactory
                if(empty($enrolledSubjInfo)){ 
                    // #2.3: check the prerequisite 1 satisfactory
                    $isPrereque1Satisfied = $this->isSubjectPrereqSatisfied($data['id_number'], $curri_subj->curricSubj_prerequisite1);
                    // #2.4: check the prerequisite 2 satisfactory
                    $isPrereque2Satisfied = $this->isSubjectPrereqSatisfied($data['id_number'], $curri_subj->curricSubj_prerequisite2);
                    // #2.5: if the prerequisites are satisfied the subject will be included to the available subjects for student
                    if($isPrereque1Satisfied && $isPrereque2Satisfied){
                        $availableSubjs[$curri_subj->subject_uid] = [
                            'subj_code' => $curri_subj->subject_code,
                            'subj_description' => $curri_subj->subject_desc,
                            'subj_units' => ($curri_subj->curricSubj_labUnits + $curri_subj->curricSubj_lecUnits),
                        ];
                    }
                }
                // #2.2.2: if the subject is found/enrolled proceed to checking the remarks satifactory
                else {
                    // #2.2.3: check the subject's remarks satisfactory
                    $isEnrolledSubjectRemarksSatisfied = $this->isSubjectRemarksSatisfied($enrolledSubjInfo);
                    // #2.2.4: if the enrolled subject's remarks are not satisfied. the subject will be added
                    // to the available subjects
                    if(!$isEnrolledSubjectRemarksSatisfied){ 
                        $availableSubjs[$curri_subj->subject_uid] = [
                            'subj_code' => $curri_subj->subject_code,
                            'subj_description' => $curri_subj->subject_desc,
                            'subj_units' => ($curri_subj->curricSubj_labUnits + $curri_subj->curricSubj_lecUnits),
                        ];
                    }
                }
            }
            // #2.1.2: if the subject is found/enrolled proceed to checking the remarks satifactory
            else {
                // #2.1.3: check the subject's remarks satisfactory
                $isEnrolledSubjectRemarksSatisfied = $this->isSubjectRemarksSatisfied($enrolledSubjInfo);
                // #2.1.4: if the enrolled subject's remarks are not satisfied. the subject will be added
                // to the available subjects
                
                $logger->error($isEnrolledSubjectRemarksSatisfied);

                if(!$isEnrolledSubjectRemarksSatisfied){ 
                    $availableSubjs[$curri_subj->subject_uid] = [
                        'subj_code' => $curri_subj->subject_code,
                        'subj_description' => $curri_subj->subject_desc,
                        'subj_units' => ($curri_subj->curricSubj_labUnits + $curri_subj->curricSubj_lecUnits),
                    ];
                }
            }

        }

        return $availableSubjs;
    }

    public function getCurriculumSubjects($curr_uid, $year_level, $sem){

        //setting up test_drupal_data database into active connection
        Database::setActiveConnection('test_drupal_data');
        // get the active connection and put into an object
        $connection = Database::getConnection();

        $query = $connection->query('SELECT * 
        FROM curriculum_subjects,subjects
        WHERE subjects.subject_uid = curriculum_subjects.subject_uid
        AND subjects.subject_isActive = :isActive
        AND curriculum_subjects.curriculum_uid = :curr_uid
        AND curriculum_subjects.curricSubj_year = :year_level
        AND curriculum_subjects.curricSubj_sem = :semester',
        [
            ':isActive' => 'active',
            ':curr_uid' => $curr_uid,
            ':year_level' => $year_level,
            ':semester' => $sem
        ]);

        $result = $query->fetchAll();

        Database::closeConnection();

        return $result;

    }

    public function getEnrolledSubjectInfoByCode($stud_id, $subject_code){
        //setting up test_drupal_data database into active connection
        Database::setActiveConnection('test_drupal_data');
        // get the active connection and put into an object
        $connection = Database::getConnection();

        //query by subject code
        $query = $connection->query('SELECT * 
        FROM students, subjects, students_subjects
        WHERE students.student_uid = students_subjects.student_uid
        AND subjects.subject_uid = students_subjects.subject_uid 
        AND students.student_schoolId = :stud_id
        AND subjects.subject_code LIKE :subj_code', 
        [
            ':stud_id' => $stud_id,
            ':subj_code' => '%'.$subject_code.'%',
        ]);

        $result = $query->fetchAll();

        Database::closeConnection();

        return $result;
    }

    public function getEnrolledSubjectInfoByDesc($stud_id, $subject_desc){
        //setting up test_drupal_data database into active connection
        Database::setActiveConnection('test_drupal_data');
        // get the active connection and put into an object
        $connection = Database::getConnection();

        //query by subject code
        $query = $connection->query('SELECT * 
        FROM students, subjects, students_subjects
        WHERE students.student_uid = students_subjects.student_uid
        AND subjects.subject_uid = students_subjects.subject_uid 
        AND students.student_schoolId = :stud_id
        AND subjects.subject_desc LIKE :subj_desc', 
        [
            ':stud_id' => $stud_id,
            ':subj_desc' => '%'.$subject_desc.'%',
        ]);

        $result = $query->fetchAll();

        if(empty($result))

        Database::closeConnection();

        return $result;
    }

    public function isSubjectPrereqSatisfied($stud_id, $preRequi_uid){

        if($preRequi_uid == 'none'){
            return true;
        }
        else {
            $stud_info = $this->getStudentInfo($stud_id);
            $remarks = $this->getStudSubjectRemarks($stud_info[0]->student_uid, $preRequi_uid);
            return $this->isSubjectRemarksSatisfied($remarks);
        }

    }

    public function isSubjectRemarksSatisfied($subject){
    
        if(empty($subject)){
            return false;
        }
        else {
            if($subject[0]->studSubj_remarks == 'INC' ||
             empty($subject[0]->studSubj_remarks)){

                if($subject[0]->studSubj_finalRemarks == 'INC' || 
                $subject[0]->studSubj_finalRemarks == 'DRP' || 
                $subject[0]->studSubj_finalRemarks == 'DROP' || 
                $subject[0]->studSubj_finalRemarks > 3 || 
                empty($subject)){
                    return false;
                }
                else {
                    return true;
                }
            }
            elseif ($subject[0]->studSubj_remarks == 'DRP' || 
            $subject[0]->studSubj_remarks == 'DROP' || 
            $subject[0]->studSubj_remarks > 3) {
                return false;
            }
            else {
                return true;
            }

        }
        
    }

    // 

    public function getCurriculumSubjectsByCategory($curri_uid, $subj_cat){
        //setting up test_drupal_data database into active connection
        Database::setActiveConnection('test_drupal_data');
        // get the active connection and put into an object
        $connection = Database::getConnection();

        $query = $connection->query('SELECT *
        FROM subjects_category, subjects, curriculum_subjects
        WHERE subjects_category.subjCat_uid = subjects.subjCat_uid
        AND subjects.subject_uid = curriculum_subjects.subject_uid
        AND subjects_category.subjCat_uid = :subjCat_uid
        AND curriculum_subjects.curriculum_uid = :curri_uid', 
        [
            ':subjCat_uid' => $subj_cat,
            ':curri_uid' => $curri_uid,
        ]);

        $result = $query->fetchAll();

        Database::closeConnection();

        return $result;
    }

    public function getCurriculumElectiveSubjects($curri_uid){
        //setting up test_drupal_data database into active connection
        Database::setActiveConnection('test_drupal_data');
        // get the active connection and put into an object
        $connection = Database::getConnection();

        $query = $connection->query('SELECT *
        FROM curriculum_electives, subjects
        WHERE curriculum_electives.electiveSubj_uid = subjects.subject_uid
        AND curriculum_uid = :curri_uid', 
        [
            ':curri_uid' => $curri_uid,
        ]);

        $result = $query->fetchAll();

        Database::closeConnection();

        return $result;
    }

    public function getStudSubjectRemarks($stud_uid, $subject_uid){
        //setting up test_drupal_data database into active connection
        Database::setActiveConnection('test_drupal_data');
        // get the active connection and put into an object
        $connection = Database::getConnection();

        $query = $connection->query('SELECT *
        FROM students_subjects
        WHERE student_uid = :stud_uid
        AND subject_uid = :subj_uid', 
        [
            ':stud_uid' => $stud_uid,
            ':subj_uid' => $subject_uid,
        ]);

        $result = $query->fetchAll();

        Database::closeConnection();

        return $result;
    }

}

?>