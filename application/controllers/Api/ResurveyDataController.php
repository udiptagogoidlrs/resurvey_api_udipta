<?php
defined('BASEPATH') or exit('No direct script access allowed');
include APPPATH . '/libraries/CommonTrait.php';

class ResurveyDataController extends CI_Controller
{
    use CommonTrait;
    public function __construct()
    {
        parent::__construct();
    }
    public function getResurveyMasterData()
    {
        $this->load->helper('cookie');
        $authToken = $this->input->cookie('jwt_authorization', TRUE);
        jwtVerify($authToken);

        $request_data = json_decode(file_get_contents('php://input', true));
        $dist_code = $request_data->dist_code;

        header('Content-Type: application/json');
        $data = [];
        $url = LANDHUB_BASE_URL . "api/resurvey/v1/masterdata";
        $method = 'POST';
        $data['dist_code'] = $dist_code;
        $data['apikey'] = LANDHUB_APIKEY;
        $api_output = callApiV2($url, $method, $data);
        echo ($api_output);
    }
    public function getResurveyDagData()
    {
        $this->load->helper('cookie');
        $authToken = $this->input->cookie('jwt_authorization', TRUE);
        jwtVerify($authToken);

        $request_data = json_decode(file_get_contents('php://input', true));

        $locationArr = explode('-', $request_data->vill_townprt_code);

        $dist_code = $locationArr[0];
        $subdiv_code = $locationArr[1];
        $cir_code = $locationArr[2];
        $mouza_pargona_code = $locationArr[3];
        $lot_no = $locationArr[4];
        $vill_townprt_code = $locationArr[5];
        $dag_no = $request_data->dag_no;


        header('Content-Type: application/json');
        $data = [];
        $url = LANDHUB_BASE_URL . "api/resurvey/v1/dag-data";
        $method = 'POST';
        $data['dist_code'] = $dist_code;
        $data['subdiv_code'] = $subdiv_code;
        $data['cir_code'] = $cir_code;
        $data['mouza_pargona_code'] = $mouza_pargona_code;
        $data['lot_no'] = $lot_no;
        $data['vill_townprt_code'] = $vill_townprt_code;
        $data['dag_no'] = $dag_no;

        $data['apikey'] = LANDHUB_APIKEY;
        $api_output = callApiV2($url, $method, $data);

        $api_data = json_decode($api_output);
        $data = $api_data->data;
        $this->output->set_status_header(200);  // Change to 400, 401, 500, etc. as needed

        //return response
        $response['dhar_dag'] = $data->chitha_dag;
        $response['dhar_pattadars'] = $data->pattadars;
        $response['dhar_tenants'] = $data->tenants;


        $data = [];
        $url = "/NicApi/PartDags";
        $method = 'POST';
        $data['locationCode'] = $dist_code . '_' . $subdiv_code . '_' . $cir_code . '_' . $mouza_pargona_code . '_' . $lot_no . '_' . $vill_townprt_code;
        $data['oldDagNo'] = $dag_no;

        $api_output = callLandhubAPIWithHeader($method, $url, $data);

        if (empty($api_output) || $api_output['error'] != '' || $api_output['http_status'] != 200) {
            log_message("error", 'LAND HUB API FAIL');
            $response = [
                'status' => 'n',
                'msg' => 'Bhunaksa API PartDags Failed!'
            ];
            $this->output->set_status_header(500);  // Change to 400, 401, 500, etc. as needed
            $partDags = [];
            $locationCode = '';
            $inputOldDagNo = '';
            echo json_encode($response);
            exit;
        } else {
            $partDags = $api_output['data']->partDags;
            $locationCode = $api_output['data']->locationCode;
            $inputOldDagNo = $api_output['data']->inputOldDagNo;
        }


        $this->dbswitch($dist_code);

        $partDagsForEntry = [];
        $checkPartDags = [];

        if (!empty($partDags)) {
            foreach ($partDags as $partDag) {
                $part_dag = $partDag->newDagNo;
                $dag_area_sqmtr = $partDag->plotArea;
                $from_bhunaksha = 1;
                //check in chitha whether created
                $chithaPartDag = $this->db->query("SELECT cbsd.*, lcg.name as land_class_name, lcg.name_ass as land_class_name_ass, pc.patta_type FROM chitha_basic_splitted_dags cbsd 
                LEFT JOIN land_class_groups lcg ON cbsd.land_class_code = lcg.land_class_code
                LEFT JOIN patta_code pc ON cbsd.patta_type_code = pc.type_code
                WHERE dist_code=? AND subdiv_code=? AND cir_code=? AND mouza_pargona_code=? AND lot_no=? AND vill_townprt_code=? AND dag_no=? AND survey_no=?", [$dist_code, $subdiv_code, $cir_code, $mouza_pargona_code, $lot_no, $vill_townprt_code, $dag_no, $part_dag])->row();

                if (!empty($chithaPartDag)) {
                    $dag_area_sqmtr = ($chithaPartDag->dag_area_sqmtr && $chithaPartDag->dag_area_sqmtr != '') ? $chithaPartDag->dag_area_sqmtr : $dag_area_sqmtr;
                    $from_bhunaksha = 0;
                }

                $row = [];

                $row['part_dag'] = $part_dag;
                $row['name'] = $part_dag;
                $row['id'] = $part_dag . '-' . $from_bhunaksha;
                $row['from_bhunaksha'] = $from_bhunaksha;
                $row['dag_area_sqmtr'] = $dag_area_sqmtr;
                $row['old_dag_no'] = $chithaPartDag ? $chithaPartDag->dag_no : '';
                $row['current_land_class'] = $chithaPartDag->land_class_name ?? '';
                $row['current_land_class_ass'] = $chithaPartDag->land_class_name_ass ?? '';
                $row['patta_type'] = $chithaPartDag->patta_type ?? '';
                $row['patta_no'] = $chithaPartDag->patta_no ?? '';

                $partDagsForEntry[] = $row;
                $checkPartDags[] = $part_dag;
            }
        }

        $addedPartDags = $this->db->query("SELECT cbsd.*, lcg.name as land_class_name, lcg.name_ass as land_class_name_ass, pc.patta_type FROM chitha_basic_splitted_dags cbsd 
                LEFT JOIN land_class_groups lcg ON cbsd.land_class_code = lcg.land_class_code
                LEFT JOIN patta_code pc ON cbsd.patta_type_code = pc.type_code WHERE dist_code=? AND subdiv_code=? AND cir_code=? AND mouza_pargona_code=? AND lot_no=? AND vill_townprt_code=? AND dag_no=?", [$dist_code, $subdiv_code, $cir_code, $mouza_pargona_code, $lot_no, $vill_townprt_code, $dag_no])->result();

        if (!empty($addedPartDags)) {
            foreach ($addedPartDags as $addedPartDag) {
                if (!in_array(trim($addedPartDag->survey_no), $checkPartDags)) {
                    $row = [];
                    $row['part_dag'] = $addedPartDag->survey_no;
                    $row['name'] = $addedPartDag->survey_no;
                    $row['id'] = $addedPartDag->survey_no . '-0';
                    $row['from_bhunaksha'] = 0;
                    $row['dag_area_sqmtr'] = $addedPartDag->dag_area_sqmtr;
                    $row['old_dag_no'] = $addedPartDag ? $addedPartDag->dag_no : '';
                    $row['current_land_class'] = $addedPartDag->land_class_name ?? '';
                    $row['current_land_class_ass'] = $addedPartDag->land_class_name_ass ?? '';
                    $row['patta_type'] = $addedPartDag->patta_type ?? '';
                    $row['patta_no'] = $addedPartDag->patta_no ?? '';

                    $partDagsForEntry[] = $row;
                }
            }
        }
        $response['part_dags'] = $partDagsForEntry;

        echo json_encode([
            'status' => 'y',
            'msg' => 'Successfully retrieved data!',
            'data' => $response
        ]);
        return;
    }
}
