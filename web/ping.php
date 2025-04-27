<?php
function resolve_srv($host) {
    $srv_query = "_minecraft._tcp.$host";
    $records = @dns_get_record($srv_query, DNS_SRV);
    if (!empty($records)) {
        $record = $records[0];
        return [
            'host' => rtrim($record['target'], '.'),
            'port' => $record['port']
        ];
    }
    return null;
}

function check_server_status($host, $port, $is_be = false) {
    if ($is_be) {
        return check_bedrock_server($host, $port);
    } else {
        return check_java_server($host, $port);
    }
}

function check_java_server($host, $port) {
    $max_retries = 2;  //重试次数，默认值为2
    $base_timeout = 1;
    
    for ($i = 1; $i <= $max_retries; $i++) {
        $start = microtime(true);
        $fp = @fsockopen($host, $port, $errno, $errstr, $base_timeout);
        
        if ($fp) {
            fclose($fp);
            return [
                'status' => true,
                'latency' => round((microtime(true) - $start) * 1000)
            ];
        }
        
        if ($i < $max_retries) {
            usleep(300000 * $i);  
        }
    }
    
    return ['status' => false, 'latency' => 0];
}

function check_bedrock_server($host, $port) {
    $magic = hex2bin('00ffff00fefefefefdfdfdfd12345678');
    $packet = hex2bin('01').pack('Q', time()).$magic.pack('Q', mt_rand());

    $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if (!$socket) return ['status' => false, 'latency' => 0];
    
    socket_set_nonblock($socket);
    socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);  
    
    $start = microtime(true);
    $sent = @socket_sendto($socket, $packet, strlen($packet), 0, $host, $port);
    if (!$sent) {
        socket_close($socket);
        return ['status' => false, 'latency' => 0];
    }

    $response = '';
    $attempts = 0;
    while ((microtime(true) - $start) < 1.5) {  // 检测时间，默认值1.5
        $read = [$socket];
        $changed = socket_select($read, $write, $except, 0, 150000);
        
        if ($changed > 0) {
            @socket_recvfrom($socket, $response, 4096, 0, $host, $port);
            if ($response && substr($response, 0, 1) === hex2bin('1c')) {
                $latency = round((microtime(true) - $start) * 1000);
                socket_close($socket);
                return ['status' => true, 'latency' => $latency];
            }
        }
        if ($attempts++ > 3) break;
    }
    
    socket_close($socket);
    return ['status' => false, 'latency' => 0];
}

// AJAX处理
if (isset($_GET['action']) && $_GET['action'] === 'check') {
    $host = $_GET['host'] ?? '';
    $port = intval($_GET['port'] ?? 25565);
    $is_be = $_GET['is_be'] === 'true';
    
    header('Content-Type: application/json');
    echo json_encode(check_server_status($host, $port, $is_be));
    exit;
}

$nodes = [
    [
        'name' => "none",#节点名称 
        'ip' => "none",#IP支持srv解析，不行就换IP
        'desc' => "none"#介绍
    ],
    #按照这个格式续写
];
?>
<!DOCTYPE html>
<html>
<head>
<!DOCTYPE html>
<html>
<head>
    <title>Build Dream节点列表</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        /* CSS部分，别瞎几把改 */
        :root {
            --success: #4CAF50;
            --warning: #FF9800;
            --error: #F44336;
            --primary: #2196F3;
            --gray: #9E9E9E;
        }
        body { 
            font-family: 'Segoe UI', system-ui, sans-serif; 
            background: #f5f5f5;
            margin: 0;
            padding: 20px;
        }
        .page-title {
            text-align: center;
            color: #333;
            margin: 20px 0 40px;
            font-size: 2.2em;
            animation: fadeInDown 0.6s;
        }
        .node-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            max-width: 1200px;
            margin: 0 auto;
        }
        .node-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            opacity: 0;
            transform: translateY(20px);
            animation: cardEnter 0.6s forwards;
        }
        .node-desc {
            color: #666;
            font-size: 14px;
            line-height: 1.5;
            margin: 10px 0;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid var(--primary);
            animation: fadeIn 0.4s 0.2s forwards;
            opacity: 0;
        }
        @keyframes cardEnter {
            to { 
                opacity: 1;
                transform: translateY(0);
            }
        }
        .node-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .status-header {
            display: flex;
            align-items: center;
            margin-bottom: 12px;
        }
        .status-icon {
            font-size: 20px;
            margin-right: 12px;
            width: 24px;
            text-align: center;
            transition: color 0.3s ease;
        }
        .node-name {
            font-weight: 600;
            font-size: 17px;
            transition: color 0.3s ease;
        }
        .latency {
            margin: 12px 0;
            font-weight: 500;
            opacity: 0;
            animation: fadeIn 0.3s 0.3s forwards;
        }
        .ip-row {
            display: flex;
            align-items: center;
            margin-top: 16px;
            padding-top: 12px;
            border-top: 1px solid #eee;
        }
        .copy-btn {
            cursor: pointer;
            color: var(--primary);
            margin-left: 8px;
            font-size: 16px;
            transition: opacity 0.2s;
        }
        .copy-btn:hover {
            opacity: 0.8;
        }
        .page-footer {
            text-align: center;
            color: #666;
            margin-top: 40px;
            padding: 20px 0;
            border-top: 1px solid #eee;
            animation: fadeIn 0.6s 0.3s backwards;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        :root {
            --success: #4CAF50;
            --warning: #FF9800;
            --error: #F44336;
            --primary: #2196F3;
            --gray: #9E9E9E;
        }
        body { 
            font-family: 'Segoe UI', system-ui, sans-serif; 
            background: #f5f5f5;
            margin: 0;
            padding: 20px;
        }
        .page-title {
            text-align: center;
            color: #333;
            margin: 20px 0 40px;
            font-size: 2.2em;
            animation: fadeInDown 0.6s;
        }
        .node-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            max-width: 1200px;
            margin: 0 auto;
        }
        .node-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            opacity: 0;
            transform: translateY(20px);
            animation: cardEnter 0.6s forwards;
        }
        @keyframes cardEnter {
            to { 
                opacity: 1;
                transform: translateY(0);
            }
        }
        .node-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .status-header {
            display: flex;
            align-items: center;
            margin-bottom: 12px;
        }
        .status-icon {
            font-size: 20px;
            margin-right: 12px;
            width: 24px;
            text-align: center;
            transition: color 0.3s ease;
        }
        .node-name {
            font-weight: 600;
            font-size: 17px;
            transition: color 0.3s ease;
        }
        .latency {
            margin: 12px 0;
            font-weight: 500;
            opacity: 0;
            animation: fadeIn 0.3s 0.3s forwards;
        }
        .ip-row {
            display: flex;
            align-items: center;
            margin-top: 16px;
            padding-top: 12px;
            border-top: 1px solid #eee;
        }
        .copy-btn {
            cursor: pointer;
            color: var(--primary);
            margin-left: 8px;
            font-size: 16px;
            transition: opacity 0.2s;
        }
        .copy-btn:hover {
            opacity: 0.8;
        }
        .page-footer {
            text-align: center;
            color: #666;
            margin-top: 40px;
            padding: 20px 0;
            border-top: 1px solid #eee;
            animation: fadeIn 0.6s 0.3s backwards;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>
<body>
<h1 class="page-title">Build Dream节点列表</h1>
    
    <div class="node-container">
        <?php foreach ($nodes as $index => $node): 
            $ip = $node['ip'];
            $ipParts = explode(':', $ip, 2);
            $host = $ipParts[0] ?? '';
            $originalPort = isset($ipParts[1]) ? (int)$ipParts[1] : null;
            $is_be = ($originalPort === 19132);

            // SRV解析部分，动了炸，懂？？？
            $detect_host = $host;
            $detect_port = $originalPort ?? ($is_be ? 19132 : 25565);
            
            if (!$is_be) {
                if ($srv_info = resolve_srv($host)) {
                    $detect_host = $srv_info['host'];
                    $detect_port = $srv_info['port'];
                }
            }
        ?>
        <div class="node-card" 
             data-host="<?= htmlspecialchars($detect_host) ?>" 
             data-port="<?= $detect_port ?>" 
             data-is-be="<?= $is_be ? 'true' : 'false' ?>"
             style="animation-delay: <?= $index * 0.1 ?>s">
            <div class="status-header">
                <div class="status-icon">
                    <i class="fas fa-spinner fa-spin"></i>
                </div>
                <div class="node-name"><?= htmlspecialchars($node['name']) ?></div>
            </div>
            
            <?php if (!empty($node['desc'])): ?>
            <div class="node-desc">
                <?= htmlspecialchars($node['desc']) ?>
            </div>
            <?php endif; ?>
            
            <div class="server-info">
                <div class="latency">检测中...</div>
            </div>
            <div class="ip-row">
                <span><?= htmlspecialchars($node['ip']) ?></span>
                <i class="fas fa-clone copy-btn" onclick="copyip('<?= htmlspecialchars($node['ip']) ?>')"></i>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <footer class="page-footer">
        <p>© 2025 BuildDream 节点列表</p>
        <p>Powered by EXE_autumnwind</p>
    </footer>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // 异步检测所有节点
        const cards = document.querySelectorAll('.node-card');
        cards.forEach((card, index) => {
            const detectHost = card.dataset.host;
            const detectPort = card.dataset.port;
            const isBe = card.dataset.isBe === 'true';
            
            const statusIcon = card.querySelector('.status-icon i');
            const latencyEl = card.querySelector('.latency');
            const nodeName = card.querySelector('.node-name');

            fetch(`?action=check&host=${encodeURIComponent(detectHost)}&port=${detectPort}&is_be=${isBe}`)
                .then(r => r.json())
                .then(data => {
                    statusIcon.classList.remove('fa-spinner', 'fa-spin');
                    let iconClass, color;
                    
                    if (data.status) {
                        iconClass = data.latency <= 50 ? 'fa-check-circle' //423-427行的两组数字分别对应的是低延迟和延迟过高的判定值，此行是低延迟判定，需和426行一起修改
                                    : data.latency <= 1000 ? 'fa-exclamation-circle' //此行是延迟过高判定，需和427行一起更改
                                    : 'fa-times-circle';
                        color = data.latency <= 50 ? '#4CAF50' 
                               : data.latency <= 1000 ? '#FF9800' 
                               : '#F44336';
                    } else {
                        iconClass = 'fa-times-circle';
                        color = '#F44336';
                    }

                    statusIcon.classList.add(iconClass);
                    statusIcon.style.color = color;
                    latencyEl.textContent = data.status ? `${data.latency}ms` : '无法连接';
                    latencyEl.style.color = color;
                    nodeName.style.color = color;
                })
                .catch(e => {
                    console.error('检测失败:', e);
                    statusIcon.classList.replace('fa-spinner', 'fa-times-circle');
                    statusIcon.style.color = '#F44336';
                    latencyEl.textContent = '检测失败';
                });
        });
    });
/* 以下是复制部分，别瞎几把改，改了无法检测 */
    function copyip(text) {
        navigator.clipboard.writeText(text).then(() => {
            const toast = document.createElement('div');
            toast.textContent = '✓ 已复制';
            toast.style.cssText = `
                position: fixed;
                bottom: 20px;
                left: 50%;
                transform: translateX(-50%);
                background: #4CAF50;
                color: white;
                padding: 8px 16px;
                border-radius: 4px;
                animation: fadeIn 0.3s;
            `;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 2000);
            
        });
    }
    </script>
</body>
</html>
