<?php
/**
 * PayController.php
 * @Version             1.0
 * =============================================
 * @Copyright(C)        2017  GUHAO
 * @Author              guhao
 * =============================================
 * @Date                2017/4/15
 */

namespace App\Http\Controllers;

use App\Http\Models\Driver;
use App\Http\Models\OrderPay;
use App\Http\Models\Orders;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Log;
use EasyWeChat\Foundation\Application;
use EasyWeChat\Payment\Order;
use Illuminate\Support\Facades\Request;

class PayController extends Controller {
    //
    public function __construct() {
        parent::__construct();
    }

    public $rsaPrivateKey = '';

    public $alipayrsaPublicKey = '';

    /**
     * @name 支付宝支付
     * @method post
     * @uri /api/pay/alipay
     * @param order_sn string 订单ID
     * @param order_price float 订单价格
     * @param order_title string 订单标题
     * @param order_body string 订单描述
     * @response data string 支付字符串
     * @response ### ### ###
     * @response code int 501(缺少参数order_no)
     * @response code int 502(缺少参数order_price)
     * @response code int 503(缺少参数order_title)
     * @response code int 504(缺少参数order_body)
     */
    public function alipay() {

        //require_once app_path() . "/Aop/request/AlipayTradeAppPayRequest.php";
        include(app_path()."/Aop/AopSdk.php");


        if (!Request::has("order_sn")) {
            return response()->json(['code' => 501, 'msg'=>'缺少参数order_sn']);
        }
        if (!Request::has("order_price")) {
            return response()->json(['code' => 502, 'msg'=>'缺少参数order_sn']);
        }
        if (!Request::has("order_title")) {
            return response()->json(['code' => 503, 'msg'=>'缺少参数order_sn']);
        }
        if (!Request::has("order_body")) {
            return response()->json(['code' => 504, 'msg'=>'缺少参数order_sn']);
        }

        $order_sn = Request::input("order_sn");
        $order_price = Request::input("order_price");
        $order_title = Request::input("order_title");
        $order_body = Request::input("order_body");

        $aop = new \AopClient();
        $aop->gatewayUrl = "https://openapi.alipay.com/gateway.do";
        $aop->appId = config("alipay.appid");
        $aop->rsaPrivateKey = $this->rsaPrivateKey;
        $aop->format = "json";
        $aop->charset = "UTF-8";
        $aop->signType = "RSA2";
        $aop->alipayrsaPublicKey = $this->alipayrsaPublicKey;
        //实例化具体API对应的request类,类名称和接口名称对应,当前调用接口名称：alipay.trade.app.pay
        $request = new \AlipayTradeAppPayRequest();
        //SDK已经封装掉了公共参数，这里只需要传入业务参数
        $content = [
            "body" => $order_body,
            "subject" => $order_title,
            "out_trade_no" => $order_sn,
            "timeout_express" => "30m",
            "total_amount" => $order_price,
            "product_code" => "QUICK_MSECURITY_PAY",
        ];
        $bizcontent = json_encode($content);

        $request->setNotifyUrl("http://zhyz.yichujilian.com/api/pay/alipay_notify");
        $request->setBizContent($bizcontent);
        //这里和普通的接口调用不同，使用的是sdkExecute
        $response = $aop->sdkExecute($request);
        //htmlspecialchars是为了输出到页面时防止被浏览器将关键参数html转义，实际打印到日志以及http传输不会有这个问题
        //$orderStr = htmlspecialchars($response);//就是orderString 可以直接给客户端请求，无需再做处理。

        return response()->json(['code' => 0, 'data' => $response,  'msg'=>'success']);
        //return $orderStr;
    }

    public function alipayNotify(Request $request) {
        $aop = new \AopClient;
        $aop->alipayrsaPublicKey = $this->alipayrsaPublicKey;
        $flag = $aop->rsaCheckV1($_POST, NULL, "RSA");
        if (!$flag) {
            return "failure";
        }

        // 判断通知类型。
        switch (Input::get('trade_status')) {
            case 'TRADE_SUCCESS':
            case 'TRADE_FINISHED':
            $order_sn = $request->input('out_trade_no');
            $trade_no = $request->input('trade_no');
            if (strpos($order_sn, 'O') == 0) {
                // 订单支付
            } elseif (strpos($order_sn, 'GB') == 0) {
                // 团购支付
            } elseif (strpos($order_sn, 'MU') == 0) {
                // 我的小猪支付
            } elseif (strpos($order_sn, 'FU') == 0) {
                // 众筹支付
            }

                break;
        }

        return "success";
    }

    /**
     * @name 支付宝支付回调
     * @method post
     * @uri /v1/pay/alinotify
     * @param order_no string 订单号
     * @param uid string 用户ID
     * @response code int 501(缺少参数order_no)
     * @response code int 502(缺少参数uid)
     * @response code int 503(订单不存在)
     */
    public function payNotify() {
        $data = $this->row();

        $info = json_decode($data);

        if(!array_key_exists("order_no", $info) || empty($info->order_no)) {
            return response()->json(['code' => 501, 'msg'=>'订单号不能为空']);
        }

        if(!array_key_exists("uid", $info) || empty($info->uid)) {
            return response()->json(['code' => 502, 'msg'=>'用户ID不能为空']);
        }

        $orderPay = OrderPay::where("order_no", $info->order_no)->first();
        if (!$orderPay) {
            return response()->json(['code' => 503, 'msg'=>'订单未找到']);
        }

        $orderPay->status = 2;
        $orderPay->pay_way = "alipay";
        $orderPay->pay_time = new \DateTime();
        $orderPay->trade_no = $info->order_no;
        $orderPay->buyer_logon_id = $info->uid;
        $orderPay->save();

        $order = Orders::where('order_no',$info->order_no)->first();
        $order->status = 6;
        $order->pay_way = "alipay";
        $order->save();

        $driver = Driver::find($order->driver_id);
        $driver->balance += $order->brokerage;
        $driver->save();

        return response()->json(['code' => 0, 'msg'=>'success']);
    }


    protected function options(){ //选项设置
        return [
            // 前面的appid什么的也得保留哦
            'app_id'  => '你的APPID', //你的APPID
            'secret'  => 'AppSecret',     // AppSecret
            // 'token'   => 'your-token',          // Token
            // 'aes_key' => '',                    // EncodingAESKey，安全模式下请一定要填写！！！
            // ...
            // payment
            'payment' => [
                'merchant_id'        => '',
                'key'                => '',
                // 'cert_path'          => 'path/to/your/cert.pem', // XXX: 绝对路径！！！！
                // 'key_path'           => 'path/to/your/key',      // XXX: 绝对路径！！！！
                'notify_url'         => 'http://taxi.yichujilian.com/v1/pay/wxpay_notify',       // 你也可以在下单时单独设置来想覆盖它
                // 'device_info'     => '013467007045764',
                // 'sub_app_id'      => '',
                // 'sub_merchant_id' => '',
                // ...
            ],
        ];
    }

    /**
     * @name 微信支付
     * @method post
     * @uri /v1/pay/wxpay
     * @param order_no string 订单ID
     * @param order_price float 订单价格
     * @param order_title string 订单标题
     * @param order_body string 订单描述
     * @response data string 支付字符串
     * @response ### ### ###
     * @response code int 501(缺少参数order_no)
     * @response code int 502(订单不存在)
     * @response code int 503(缺少参数order_price)
     * @response code int 504(缺少参数order_title)
     * @response code int 505(缺少参数order_body)
     */
    public function wxPay() {
        $data = $this->row();

        $pay_info = json_decode($data);

        if(!array_key_exists("order_no", $pay_info) || empty($pay_info->order_no)) {
            return response()->json(['code' => 501, 'msg'=>'订单号不能为空']);
        }

        $order = Orders::where("order_no", $pay_info->order_no)->first();
        if (!$order) {
            return response()->json(['code' => 502, 'msg'=>'订单不存在']);
        }

        if(!array_key_exists("order_price", $pay_info) || empty($pay_info->order_price)) {
            return response()->json(['code' => 503, 'msg'=>'订单价格不能为空']);
        }

        if(!array_key_exists("order_title", $pay_info) || empty($pay_info->order_title)) {
            return response()->json(['code' => 504, 'msg'=>'订单标题不能为空']);
        }

        if(!array_key_exists("order_body", $pay_info) || empty($pay_info->order_body)) {
            return response()->json(['code' => 505, 'msg'=>'订单描述不能为空']);
        }

        $price = $pay_info->order_price * 100;

        /*$wxPay = new WechatAppPay();

        $preorder = $wxPay->getPrePayOrder($pay_info->order_body,$pay_info->order_no,$price);

        if ($preorder["return_code"] == "SUCCESS" && $preorder["result_code"] == "SUCCESS") {
            $payconf = $wxPay->getOrder($preorder["prepay_id"]);
            return response()->json(['code' => 0,'data'=>$payconf, 'msg'=>'success']);
        } else {
            return response()->json(['code' => 500, 'msg'=>$preorder["err_code_des"]]);
        }*/

        $app = new Application($this->options());
        $payment = $app->payment;

        $attributes = [
            'trade_type'       => 'APP', // JSAPI，NATIVE，APP...
            'body'             => $pay_info->order_title,
            'detail'           => $pay_info->order_body,
            'out_trade_no'     => $pay_info->order_no,
            'total_fee'        => $price, // 单位：分
            'notify_url'       => 'http://taxi.yichujilian.com/v1/pay/wxpay_notify', // 支付结果通知网址，如果不设置则会使用配置里的默认地址
            //'openid'           => $pay_info->openid, // trade_type=JSAPI，此参数必传，用户在商户appid下的唯一标识，
            // ...
        ];

        // 创建订单
        $payorder = new Order($attributes);

        $result = $payment->prepare($payorder);
        if ($result->return_code == 'SUCCESS' && $result->result_code == 'SUCCESS'){
            // return response()->json(['result'=>$result]);
            $prepayId = $result->prepay_id;
            $config = $payment->configForAppPayment($prepayId);
            //$sign = \EasyWeChat\Payment\generate_sign($config,$this->options()["payment"]["key"]);
            //$config["sign"] = $sign;
            return response()->json(['code' => 0,'data'=>$config, 'msg'=>'success']);
        } else {
            return response()->json(['code' => 500,'data'=>$result, 'msg'=>'支付失败']);
        }
    }

    public function wxNotify() {
        $options = $this->options();
        $app = new Application($options);
        $response = $app->payment->handleNotify(function($notify, $successful){
            // 使用通知里的 "微信支付订单号" 或者 "商户订单号" 去自己的数据库找到订单
            $orderPay = OrderPay::where('order_no',$notify->out_trade_no)->first();
            if (count($orderPay) <= 0) { // 如果订单不存在
                return 'Order not exist.'; // 告诉微信，我已经处理完了，订单没找到，别再通知我了
            }

            $order = Orders::where('order_no',$notify->out_trade_no)->first();

            $driver = Driver::find($order->driver_id);

            // 如果订单存在
            // 检查订单是否已经更新过支付状态
            if ($orderPay->status == 2) {
                return true; // 已经支付成功了就不再更新了
            }
            // 用户是否支付成功
            if ($successful) {
                $order->status = 6;
                $order->pay_way = "wxpay";

                // 不是已经支付状态则修改为已经支付状态
                $orderPay->status = 2;
                $orderPay->pay_way = "wxpay";
                $orderPay->pay_time = new \DateTime();
                $orderPay->trade_no = $notify->out_trade_no;
                $orderPay->buyer_logon_id = Input::get("device_info");

                $driver->balance += $order->brokerage;

            } else { // 用户支付失败
                $order->status = 5;
                $orderPay->status = 1; //待付款
            }
            $order->save();
            $orderPay->save(); // 保存订单
            $driver->save();
            return true; // 返回处理完成
        });
    }


    /**
     * @name 现金支付
     * @method post
     * @uri /v1/pay/cash
     * @param order_no string 订单号
     * @param order_price float 订单价格
     * @response code int 501(缺少参数order_no)
     * @response code int 502(订单不存在)
     * @response code int 503(缺少参数order_price)
     * @response code int 504(付款单未找到)
     */
    public function cash() {
        $data = $this->row();

        $pay_info = json_decode($data);

        if(!array_key_exists("order_no", $pay_info) || empty($pay_info->order_no)) {
            return response()->json(['code' => 501, 'msg'=>'订单号不能为空']);
        }

        $order = Orders::with("payinfo")->where("order_no", $pay_info->order_no)->first();
        if (!$order) {
            return response()->json(['code' => 502, 'msg'=>'订单不存在']);
        }

        if(!array_key_exists("order_price", $pay_info) || empty($pay_info->order_price)) {
            return response()->json(['code' => 503, 'msg'=>'订单价格不能为空']);
        }

        DB::beginTransaction();

        $orderPay = OrderPay::where("order_no", $pay_info->order_no)->first();
        if (!$orderPay) {
            return response()->json(['code' => 504, 'msg'=>'付款单未找到']);
        }

        $order = Orders::where('order_no',$pay_info->order_no)->first();
        $order->pay_way = "cash";
        $order->status = 6;

        $orderPay->status = 2;
        $orderPay->pay_way = "cash";
        $orderPay->price = $pay_info->order_price;
        $orderPay->pay_time = new \DateTime();
        $orderPay->trade_no = $pay_info->order_no;
        $orderPay->buyer_logon_id = $order->user_id;

        if (!$order->save()) {
            DB::rollBack();
            return response()->json(['code' => 500, 'msg'=>'支付失败']);
        }

        if ($orderPay->save()) {
            DB::commit();
            return response()->json(['code' => 0, 'msg'=>'付款成功']);
        } else {
            DB::rollBack();
            return response()->json(['code' => 500, 'msg'=>'支付失败']);
        }


    }

}