<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use App\Models\RouterOs;
use App\MyHelper\RouterosAPI;

class RouterosController extends Controller
{
    public $API = [], $routeros_data = [], $connection;

    public function test_api()
    {
        try {
            return response()->json([
                'success' => true,
                'message' => 'Welcome in Routeros API'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetch data Routeros API'
            ]);
        }
    }

    public function store_routeros($data)
    {
        $API = new RouterosAPI;
        $connection = $API->connect($data['ip_address'], $data['login'], $data['password']);

        if (!$connection) return response()->json(['error' => true, 'message' => 'Routeros not connected ...'], 404);

        $store_routeros_data = [
            'identity' => $API->comm('/system/identity/print')[0]['name'],
            'ip_address' => $data['ip_address'],
            'login' => $data['login'],
            'password' => $data['password'],
            'connect' => $connection
        ];

        $store_routeros = new RouterOs;
        $store_routeros->identity = $store_routeros_data['identity'];
        $store_routeros->ip_address = $store_routeros_data['ip_address'];
        $store_routeros->login = $store_routeros_data['login'];
        $store_routeros->password = $store_routeros_data['password'];
        $store_routeros->connect = $store_routeros_data['connect'];
        $store_routeros->save();

        return response()->json([
            'success' => true,
            'message' => 'Routeros data has been saved to database laravel',
            'routeros_data' => $store_routeros
        ]);
    }

    public function routeros_connection(Request $request)
    {
        try {

            $validator = Validator::make($request->all(), [
                'ip_address' => 'required',
                'login' => 'required',
                'password' => 'required'
            ]);

            if ($validator->fails()) return response()->json($validator->errors(), 404);

            $req_data = [
                'ip_address' => $request->ip_address,
                'login' => $request->login,
                'password' => $request->password
            ];

            $routeros_db = RouterOs::where('ip_address', $req_data['ip_address'])->get();

            if (count($routeros_db) > 0) {
                if ($this->check_routeros_connection($request->all())):
                    return response()->json([
                        'connect' => true,
                        'message' => 'Routeros have a connection from database',
                        'routeros_data' => $this->routeros_data
                    ]);

                else:
                    return response()->json([
                        'error' => true,
                        'message' => 'Routeros not connected, check administrator login !'
                    ]);
                endif;
            } else {
                return $this->store_routeros($request->all());
            }
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetch data Routeros API, ' . $e->getMessage()
            ]);
        }
    }

    public function check_routeros_connection($data)
    {

        $routeros_db = RouterOs::where('ip_address', $data['ip_address'])->get();

        if (count($routeros_db) > 0):
            $API = new RouterosAPI;
            $connection = $API->connect($routeros_db[0]['ip_address'], $routeros_db[0]['login'], $routeros_db[0]['password']);

            if ($routeros_db[0]['connect'] !== $connection) $update_routerosdb_connection = RouterOs::where('id', $routeros_db[0]['id'])->update(['connect' => $connection]);

            if (!$connection) return false;

            $this->API = $API;
            $this->connection = $connection;

            // Get connected users from active hotspot
            // $active_users = $API->comm('/ip/hotspot/active/print');

            // OR for PPP connections (optional)
            $active_users = $API->comm('/ppp/active/print');

            // Get interface traffic stats (Rx, Tx)
            $interface_traffic = $API->comm('/interface/print');
            $this->routeros_data = [
                'identity' => $this->API->comm('/system/identity/print')[0]['name'],
                'ip_address' => $routeros_db[0]['ip_address'],
                'login' => $routeros_db[0]['login'],
                'password' => Hash::make($routeros_db[0]['password']),
                'connect' => $connection,
                'active_users' => $active_users,
                'traffic' => $interface_traffic,
            ];
            return true;
        else:
            echo "Routeros data not available in database, please create connection again !";
        endif;
    }

    public function set_interface(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'ip_address' => 'required',
                'id' => 'required',
                'interface' => 'required',
                'name' => 'required'
            ]);

            if ($validator->fails()) return response()->json($validator->errors(), 404);

            if ($this->check_routeros_connection($request->all())):
                $interface_lists = $this->API->comm('/interface/print');
                $find_interface = array_search($request->name, array_column($interface_lists, 'name'));

                // var_dump($find_interface); die;

                if (!$find_interface):
                    $set_interface = $this->API->comm('/interface/set', [
                        '.id' => "*$request->id",
                        'name' => "$request->name"
                    ]);

                    return response()->json([
                        'success' => true,
                        'message' => "Successfully set interface from : $request->interface, to : $request->name",
                        'interface_lists' => $this->API->comm('/interface/print')
                    ]);

                else:
                    return response()->json([
                        'success' => false,
                        'message' => "Interface name : $request->name, with .id : *$request->id has already been taken from routeros",
                        'interface_lists' => $this->API->comm('/interface/print')
                    ]);
                endif;

            endif;
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetch data Routeros API, ' . $e->getMessage()
            ]);
        }
    }

    public function add_new_address(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'ip_address' => 'required',
                'address' => 'required',
                'interface' => 'required'
            ]);

            if ($validator->fails()) return response()->json($validator->errors(), 404);

            if ($this->check_routeros_connection($request->all())):
                $interface_lists = $this->API->comm('/ip/address/print');

                $find_interface = array_search($request->interface, array_column($interface_lists, 'interface'));

                if ($find_interface) return response()->json(['error' => true, 'message' => "Interface $request->interface, have a such ip address on routeros", "suggestion" => "Did you want to editing ip address from interface : $request->interface", 'address_lists' => $this->API->comm('/ip/address/print')]);

                $add_address = $this->API->comm('/ip/address/add', [
                    'address' => $request->address,
                    'interface' => $request->interface
                ]);

                $list_address = $this->API->comm('/ip/address/print');

                $find_address_id = array_search($add_address, array_column($list_address, '.id'));

                if ($find_address_id):
                    return response()->json([
                        'success' => true,
                        'message' => "Successfully added new address from interface : $request->interface",
                        'address_lists' => $list_address
                    ]);
                else:
                    return response()->json([
                        'success' => false,
                        'message' => $add_address['!trap'][0]['message'],
                        'address_lists' => $list_address,
                        'routeros_data' => $this->routeros_data
                    ]);
                endif;
            endif;
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetch data Routeros API, ' . $e->getMessage()
            ]);
        }
    }

    public function add_ip_route(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'ip_address' => 'required',
                'gateway' => 'required'
            ]);
            if ($validator->fails()) return response()->json($validator->errors(), 404);

            if ($this->check_routeros_connection($request->all())):
                $route_lists = $this->API->comm('/ip/route/print');
                $find_route_lists = array_search($request->gateway, array_column($route_lists, 'gateway'));

                if ($find_route_lists === 0):
                    return response()->json([
                        'success' => false,
                        'message' => "Gateway address : $request->gateway has already been taken",
                        'route_lists' => $this->API->comm('/ip/route/print')
                    ]);

                else:
                    $add_route_lists = $this->API->comm('/ip/route/add', [
                        'gateway' => $request->gateway
                    ]);
                    return response()->json([
                        'success' => true,
                        'message' => "Successfully added new routes with gateway : $request->gateway",
                        'route_lists' => $this->API->comm('/ip/route/print'),
                        'routeros_data' => $this->routeros_data
                    ]);
                endif;

            endif;
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetch data Routeros API, ' . $e->getMessage()
            ]);
        }
    }

    public function add_dns_servers(Request $request)
    {
        try {
            $schema = [
                'ip_address' => 'required',
                'servers' => 'required',
                'remote_requests' => 'required'
            ];

            $validator = Validator::make($request->all(), $schema);

            if ($validator->fails()) return response()->json($validator->errors(), 404);

            if ($this->check_routeros_connection($request->all())):
                $add_dns = $this->API->comm('/ip/dns/set', [
                    'servers' => $request->servers,
                    'allow-remote-requests' => 'yes'
                ]);

                $dns_lists = $this->API->comm('/ip/dns/print');

                if (count($add_dns) == 0):
                    return response()->json([
                        'success' => true,
                        'message' => 'Successfully addedd new dns servers',
                        'dns_lists' => $dns_lists
                    ]);
                else:
                    return response()->json([
                        'success' => false,
                        'message' => 'Failed added dns servers',
                        'routeros_data' => $this->routeros_data
                    ]);
                endif;

            endif;
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetch data Routeros API, ' . $e->getMessage()
            ]);
        }
    }

    public function masquerade_srcnat(Request $request)
    {
        try {
            $schema = [
                'ip_address' => 'required',
                'chain' => 'required',
                'protocol' => 'required',
                'out_interface' => 'required',
                'action' => 'required'
            ];
            $validator = Validator::make($request->all(), $schema);
            if ($validator->fails()) return response()->json($validator->errors(), 404);

            if ($this->check_routeros_connection($request->all())):
                $check_src_nat = $this->API->comm('/ip/firewall/nat/print');

                if (count($check_src_nat) == 0):
                    $add_firewall_nat = $this->API->comm('/ip/firewall/nat/add', [
                        'chain' => $request->chain,
                        'action' => $request->action,
                        'protocol' => $request->protocol,
                        'out-interface' => $request->out_interface
                    ]);

                    $firewall_nat_lists = $this->API->comm('/ip/firewall/nat/print');

                    return response()->json([
                        'success' => true,
                        'message' => "Success added firewall nat for $request->chain",
                        'nat_lists' => $firewall_nat_lists
                    ]);
                else:
                    return response()->json([
                        'error' => true,
                        'message' => "srcnat for out-interface $request->out_interface has already been taken",
                        'srcnat_lists' => $check_src_nat
                    ]);
                endif;
            endif;
        } catch (Exception $e) {
            return response()->json(['error' => true, 'message' => 'Error fetch routeros API ' . $e->getMessage()]);
        }
    }

    public function routeros_reboot(Request $request)
    {
        try {
            $schema = [
                'ip_address' => 'required'
            ];

            $validator = Validator::make($request->all(), $schema);

            if ($validator->fails()) return response()->json($validator->errors(), 404);

            if ($this->check_routeros_connection($request->all())):
                $reboot = $this->API->comm('/system/reboot');

                return response()->json([
                    'reboot' => true,
                    'message' => 'Routeros has been reboot the system',
                    'connection' => $this->connection
                ]);

            endif;
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetch data Routeros API, ' . $e->getMessage()
            ]);
        }
    }

    public function routeros_shutdown(Request $request)
    {
        try {
            $schema = [
                'ip_address' => 'required'
            ];

            $validator = Validator::make($request->all(), $schema);

            if ($validator->fails()) return response()->json($validator->errors(), 404);

            if ($this->check_routeros_connection($request->all())):
                $update_connection = RouterOs::where('ip_address', $request->ip_address)->update(['connect' => 0]);

                $new_routeros_data = RouterOs::where('ip_address', $request->ip_address)->get();

                $shutdown = $this->API->comm('/system/shutdown');

                return response()->json([
                    'shutdown' => true,
                    'message' => 'Routeros has been shutdown the system',
                    'connection' => $new_routeros_data[0]['connect']
                ]);

            endif;
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetch data Routeros API, ' . $e->getMessage()
            ]);
        }
    }
}