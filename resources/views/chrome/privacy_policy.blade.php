<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>科速诺采购助手-隐私权政策</title>

    <style>
        .wrap {
            margin: 0 400px;
            text-align:center;
            border: 1px solid #ababab;
            border-radius: 5px;
            background: #efefef;
        }
        h1 {
            font-size: 20px;
            color: rgba(0,0,0,0.8);
        }
        dl {
            text-align: left;
            padding: 12px;
            list-style: none;
        }
        dt {
            font-size: 13px;
            margin-bottom: 4px;
            font-weight: bold;
        }
        dd {
            font-size: 13px;
            margin-bottom: 8px;
            margin-inline-start: 0;
        }
    </style>
</head>
    <body>
        <div class="wrap">
            <h1>科速诺采购助手-隐私权规范</h1>
            <dl>
                <dt>科速诺采购助手谷歌插件应用场景</dt>
                <dd>处理ERP订单过程中，需要在一些电商平台采购一些商品，由于订单商品和采购商品需要互相匹配，因此在电商平台中抓取商品信息和物流信息，与ERP订单商品进行映射</dd>
                <dt>关于使用tabs权限</dt>
                <dd>从电商平台页面中抓取的数据与ERP订单商品进行映射时，需要把这个数据传送到服务端。由于嵌入到页面的程序没有能力完成此操作，因此借助tabs权限来完成商品映射</dd>
                <dt>关于使用storage权限</dt>
                <dd>storage权限只用于保存用户的token，其他数据一律不保存</dd>
                <dt>关于使用主机权限</dt>
                <dd>主机权限是用于抓取数据传递，嵌入的页面无法与用户的ERP直接进行联动，只好借助主机权限完成操作</dd>
            </dl>
        </div>
    </body>
</html>
