<html>

<head>
    <title>Yahoo Info</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <style type="text/css">
        body {
            font-family: 'Capriola', sans-serif;
            background: #f6ffed;
        }
        .wrap {
            width: 100%;
        }
        .logo {
            text-align: center;
            margin: 40px 0 0 0;
        }
        .sub a {
            color: #ff7a00;
            text-decoration: none;
            padding: 5px;
            font-size: 13px;
            font-family: arial, serif;
            font-weight: bold;
        }
    </style>
</head>


<body>
<div class="wrap">
    <div class="logo">
        <h2>{{ $msg }}</h2>
        <div class="sub">
            <p><a href="{{ _url_('/') }}">Back to Home</a></p>
        </div>
    </div>
</div>
</body>

</html>
