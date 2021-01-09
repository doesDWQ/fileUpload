<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace App\Controller;

use Hyperf\HttpServer\Annotation\AutoController;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\Guzzle\ClientFactory;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\Utils\ApplicationContext;


/**
 * Class IndexController
 * @package App\Controller
 * @AutoController
 */
class IndexController extends AbstractController
{

    /**
     * @var \Hyperf\Guzzle\ClientFactory
     */
    private $clientFactory;

    private $default;
    private $test;



    public function __construct(ClientFactory $clientFactory)
    {
        $this->clientFactory = $clientFactory;

        $this->default = ApplicationContext::getContainer()->get(\Hyperf\Logger\LoggerFactory::class)->get('default');

        $this->test = ApplicationContext::getContainer()->get(\Hyperf\Logger\LoggerFactory::class)->get('test');
    }


    public function index(ResponseInterface $response)
    {

        $handler = fopen(BASE_PATH.'/g.txt','w+');
        fseek($handler,1024*1024*4);
        var_dump(ftell($handler));
        fwrite($handler,'hello');
        fwrite($handler,'hello');

        // fclose($handler);

        //$response->json(['status'=>200,'message'=>'ok']);
        // $user = $this->request->input('user', 'Hyperf');
        // $method = $this->request->getMethod();
        // $response = $response->withHeader('hello','kitty');

        return $response;
    }

    /**
     * [ 当期只实现了分块上传 ]
     *
     * 实现断点上传的思路，找到哪些点是没有传递上去的，然后循环上传上去，后台返回标定的上传长度
     */
    public function client(){

        $file = BASE_PATH.'/local_file/a.txt';

        if(!file_exists($file)){
            return ['content'=>'文件不存在','file'=>$file];
        }

        $md5 = hash_file('md5',$file);
        $sha1 = hash_file('sha1',$file);

        $fileInfo = [
            'md5'=>$md5,
            'sha1'=>$sha1,
            'total'=>filesize($file),
        ];


        $mb = 1024*1024 *1;

        // 1m/次 上传
        $fileInfo['ext'] = pathinfo($file,PATHINFO_EXTENSION);
        $fileCnt = ceil(filesize($file) / $mb);

        // 只读打开
        $file_handler = fopen($file,'r');

        for($i=0,$start=0; $i<$fileCnt; $i++,$start+=$mb){
            $fileInfo['start'] = $start;    // 告诉服务器开始写的位置
            // 定位指针位置
            fseek($file_handler,$start);
            // 读取到数据
            $data = fread($file_handler,$mb);

            // $options 等同于 GuzzleHttp\Client 构造函数的 $config 参数
            $options = [
                'timeout'=>1024,   // 5秒超时
            ];
            // $client 为协程化的 GuzzleHttp\Client 对象
            $client = $this->clientFactory->create($options);
            $response = $client->request('POST','http://127.0.0.1:80/index/server',[
                'headers'=>[
                    'fileInfo' => json_encode($fileInfo),
                ],
                'body'=>$data,
            ]);

            // $this->default->debug('body'. (string)($response->getBody()));
            if( $response->getStatusCode() == 200 ){
                $this->default->debug($start.':文件传输完毕');
                $data = json_decode( (string)$response->getBody() ,true);
                if($data['status']=='ok'){
                    return $data['file'];
                }

            }else{
                $this->default->debug($start.':传输错误');

                // 这里应该重试一次这段上传失败的
            }
        }

        return 'success';
    }

    public function server(RequestInterface $request,ResponseInterface $response){

        $maxSize = 1024*1024*10; // 最大一次10m

        $fileInfo = $request->getHeader('fileInfo');
        $body = $request->getBody();

        if($body->getSize() > $maxSize){
            return '最大大小不符！';
        }

        if(empty($body)){
            return '携带的数数据体为空';
        }

        $fileInfo = json_decode($fileInfo[0],true);
        $start = $fileInfo['start'];

        $filePath = BASE_PATH."/server_file/{$fileInfo['md5']}/{$fileInfo['sha1']}";

        if(!file_exists($filePath)){
            mkdir($filePath,0777,true); // 递归创建
        }

        $fileJsonPath = $filePath.'/fileInfo.json';

        $fileJsonArray = [];
        if(file_exists($fileJsonPath)){
            $fileJsonArray = json_decode(file_get_contents($fileJsonPath),true);
        }

        $fileExt = '';
        if(isset($fileJsonArray['ext'])){
            $fileExt = $fileJsonArray['ext'];
        }else{
            $fileExt = $fileInfo['ext'];
            $fileJsonArray['ext'] = $fileExt;

        }

        $file = "{$filePath}/{$fileInfo['md5']}.{$fileExt}";

        $handler = fopen($file,'a+');

        defer(function ()use($handler,&$fileJsonArray,$fileJsonPath){
            fclose($handler);
            file_put_contents($fileJsonPath,json_encode($fileJsonArray)); // 将配置写入到文件
        });


        if(file_exists($file) && fstat($handler)['size'] == $fileInfo['total']){
            return $response->json(['status'=>'ok','file'=>$file]);
        }

        fseek($handler,$start);
        fwrite($handler,(string)$body,$maxSize);


        if(fstat($handler)['size'] == $fileInfo['total']){
            // 校验文件
            $md5 = hash_file('md5',$file);
            $sha1 = hash_file('sha1',$file);

            if($md5 == $fileInfo['md5'] && $sha1==$fileInfo['sha1']){
                // 标记已经处理完毕
                $fileJsonArray['uploadTotal'] = 1; // 标记上传完毕
                return $response->json(['status'=>'ok','file'=>$file]);
            }else{
                return $response->json(['status'=>'error','msg'=>'文件不完整']);
            }
        }else{
            return $response->json(['status'=>'continue','file'=>'']);
        }

    }


}
