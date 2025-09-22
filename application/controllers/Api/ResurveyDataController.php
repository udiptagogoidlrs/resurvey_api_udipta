<?php
defined('BASEPATH') or exit('No direct script access allowed');
include APPPATH . '/libraries/CommonTrait.php';

class ResurveyDataController extends CI_Controller
{
    use CommonTrait;
    private $jwt_data;

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Api/ChithaModel');
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

    public function getResurveyMasterData()
    {
        $this->load->helper('cookie');

        $raw_input = file_get_contents("php://input");
        $request_data = json_decode($raw_input);

        if (!$request_data || !isset($request_data->dist_code)) {
            header('Content-Type: application/json');
            echo json_encode([
                "success" => false,
                "message" => "Invalid request: dist_code missing"
            ]);
            return;
        }

        $dist_code = $request_data->dist_code;

        $url = LANDHUB_BASE_URL . "api/resurvey/v1/masterdata";
        $method = 'POST';
        $data = [
            "dist_code" => $dist_code,
            "apikey"    => LANDHUB_APIKEY
        ];

        $api_output = callApiV2($url, $method, $data);

        if (is_string($api_output)) {
            $api_output = json_decode($api_output, true);
        }

        $masterData = [];
        if (isset($api_output['data'])) {
            $masterData = $api_output['data']; 
        }
        $transfer_types = [];
        foreach (TRANSFER_TYPES as $key => $value) {
            $transfer_types[] = [
                "value" => $key,
                "label" => $value
            ];
        }
        $masterData['transfer_types'] = $transfer_types;
        $status  = $api_output['status']  ?? null;
        $message = $api_output['msg'] ?? null;

        header('Content-Type: application/json');
        echo json_encode([
            "status"  => $status,
            "message" => $message,
            "data" => $masterData
        ]);
    }


    public function getResurveyDagData()
    {
        $this->load->helper('cookie');

        $request_data = json_decode(file_get_contents('php://input', true));

        $location = $request_data->vill_townprt_code;

        $locationArr = explode('-', $location);

        $dist_code = $locationArr[0];
        $subdiv_code = $locationArr[1];
        $cir_code = $locationArr[2];
        $mouza_pargona_code = $locationArr[3];
        $lot_no = $locationArr[4];
        $vill_townprt_code = $locationArr[5];
        $lgd_code = $locationArr[6];
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
        $data['lgd_code'] = $lgd_code;

        $data['apikey'] = LANDHUB_APIKEY;
        $api_output = callApiV2($url, $method, $data);

        $api_data = json_decode($api_output);
        $data = $api_data->data;
        $this->output->set_status_header(200); 

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
                $row['survey_no'] = $chithaPartDag->survey_no2 ?? '';
                $row['location'] = $location;

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
                    $row['survey_no'] = $addedPartDag->survey_no2 ?? '';
                    $row['location'] = $location;

                    $partDagsForEntry[] = $row;
                }
            }
        }
        $response['part_dags'] = $partDagsForEntry;
        if(empty($lgd_code)) {
            $response = [
                'status' => 'n',
                'msg' => 'Missing Location lgd!'
            ];
            $this->output->set_status_header(500);  // Change to 400, 401, 500, etc. as needed
            echo json_encode($response);
            exit;
        }

        $requestData = [
            "lgdCode"=> $lgd_code,
            "dagNo" => $dag_no
        ];

        $ngdrsResp = callNgdrsApi("v1/search_deed.php", "POST", json_encode($requestData));

        if($ngdrsResp["http_status"] == 200 && isset($ngdrsResp["data"]) && $ngdrsResp["data"]->success == true && isset($ngdrsResp["data"]->data) && !empty($ngdrsResp["data"]->data) && isset($ngdrsResp["data"]->data->data) && !isset($ngdrsResp["data"]->data->data->message)) {
            $ngdrsData = $ngdrsResp["data"]->data->data;
        }
        else {
            $ngdrsData = [];
        }

        $response['ngdrs_docs'] = $ngdrsData;

        echo json_encode([
            'status' => 'y',
            'msg' => 'Successfully retrieved data!',
            'data' => $response
        ]);
        return;
    }

    public function getSurveyNoData(){
        $request_data = json_decode(file_get_contents('php://input', true));

        $locationArr = explode('-', $request_data->loc_code);

        $dist_code = $locationArr[0];
        $subdiv_code = $locationArr[1];
        $cir_code = $locationArr[2];
        $mouza_pargona_code = $locationArr[3];
        $lot_no = $locationArr[4];
        $vill_townprt_code = $locationArr[5];
        $lgd_code = $locationArr[6];
        $partDag = $request_data->partDag;
        $bhunaksa_survey_no = $request_data->bhunaksa_survey_no;
        $this->dbswitch($dist_code);

        $partdag = $this->db->query("SELECT cbsd.*, lcg.name as land_class_name, lcg.name_ass as land_class_name_ass, pc.patta_type FROM chitha_basic_splitted_dags cbsd 
                LEFT JOIN land_class_groups lcg ON cbsd.land_class_code = lcg.land_class_code
                LEFT JOIN patta_code pc ON cbsd.patta_type_code = pc.type_code WHERE dist_code=? AND subdiv_code=? AND cir_code=? AND mouza_pargona_code=? AND lot_no=? AND vill_townprt_code=? AND survey_no=? AND bhunaksha_survey_no=?", [$dist_code, $subdiv_code, $cir_code, $mouza_pargona_code, $lot_no, $vill_townprt_code, $partDag, $bhunaksa_survey_no])->row();
        $data['part_dag'] = $partdag;       
        echo json_encode([
            'status' => 'y',
            'msg' => 'Successfully retrieved data!',
            'data' => $data
        ]);
        return;

    }

    public function getChithaData() {
        $request_data = json_decode(file_get_contents('php://input', true));
        $id = $request_data->id;
        $requestDataArr = explode('-', $id);

        $dist_code = $requestDataArr[0];
        $subdiv_code = $requestDataArr[1];
        $cir_code = $requestDataArr[2];
        $mouza_pargona_code = $requestDataArr[3];
        $lot_no = $requestDataArr[4];
        $vill_townprt_code = $requestDataArr[5];
        $lgd_code = $requestDataArr[6];
        $dag_no = $requestDataArr[7];
        $part_dag = $requestDataArr[8];
        $dag_no_lower = $part_dag;
        $dag_no_upper = $part_dag;

        $this->dbswitch($dist_code);

        $data = $this->ChithaModel->getchithaDetailsALL($dist_code, $subdiv_code, $cir_code, $mouza_pargona_code, $lot_no, $vill_townprt_code, $dag_no_lower, $dag_no_upper);
        echo '<pre>';
        var_dump($data);
        die;
    }
}
