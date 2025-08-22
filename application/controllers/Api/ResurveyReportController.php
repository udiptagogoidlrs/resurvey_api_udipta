<?php
defined('BASEPATH') or exit('No direct script access allowed');
include APPPATH . '/libraries/CommonTrait.php';

class ResurveyReportController extends CI_Controller
{
    use CommonTrait;
    private $jwt_data;

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Api/ResurveyReportModel');

        $auth = validate_jwt();
        if (!$auth['status']) {
            $this->output
                ->set_status_header(401)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => $auth['message']]))
                ->_display();
            exit;
        }

        $this->jwt_data = $auth['data'];
    }
    public function getResurveyReportData()
    {
        $this->load->helper('cookie');


        header('Content-Type: application/json');
        $response = [
            'status' => 'y',
            'msg' => 'Resurvey Report Data fetched successfully',
            'data' => [
                'entered_splitted_dags' => $this->getEnteredSplittedDags(),
            ]
        ];
        echo json_encode($response);
    }
    public function getEnteredSplittedDags()
    {
        $CI = &get_instance();
        $data = [];
        $usercode = $this->jwt_data->usercode;

        foreach (RESURVEY_DISTRICTS as $dcode => $name) {
            try {
                $this->dbswitch($dcode);
                $dist_data = [];
                $dist_data['dist_code'] = $dcode;
                $dist_data['dist_name'] = $name;
                if ($this->db->table_exists('chitha_basic_splitted_dags')) {
                    // Get all data with patta type and land class
                    $chitha_basic_splitted_dags = $this->db->query(
                        "SELECT cb.dist_code, cb.subdiv_code, cb.cir_code, cb.mouza_pargona_code, cb.lot_no, cb.vill_townprt_code, cb.dag_no as old_dag_no, cb.survey_no as dag_no, cb.patta_no, cb.patta_type_code, pc.patta_type as patta_type, cb.land_class_code, lcg.name as land_class, cb.user_code, cb.date_entry
                        ,l_village.loc_name as village_name, l_circle.loc_name as circle_name
                        FROM chitha_basic_splitted_dags cb
                        LEFT JOIN patta_code pc ON cb.patta_type_code = pc.type_code
                        LEFT JOIN land_class_groups lcg ON cb.land_class_code = lcg.land_class_code
                        LEFT JOIN location l_village ON cb.dist_code = l_village.dist_code AND cb.subdiv_code = l_village.subdiv_code AND cb.cir_code = l_village.cir_code AND cb.mouza_pargona_code = l_village.mouza_pargona_code AND cb.lot_no = l_village.lot_no AND cb.vill_townprt_code = l_village.vill_townprt_code
                        LEFT JOIN location l_circle ON cb.dist_code = l_circle.dist_code AND cb.subdiv_code = l_circle.subdiv_code AND cb.cir_code = l_circle.cir_code
                        WHERE l_circle.mouza_pargona_code = '00'
                        AND cb.user_code = ?
                        ORDER BY cb.date_entry DESC limit 10",
                        [$usercode]
                    )->result();

                    $dist_data['chitha_basic_splitted_dags'] = $chitha_basic_splitted_dags;
                } else {
                    $dist_data['chitha_basic_splitted_dags'] = "Table not found";
                }
                $this->db->close(); // cleanup connection
                $data[$name] = $dist_data;
            } catch (Exception $e) {
                $data[$name] = "Connection failed: " . $e->getMessage();
            }
        }

        return $data;
    }
}
