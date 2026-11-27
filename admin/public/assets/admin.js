// 挽屿AI 后台脚本：负责登录签名计算
(function () {
    // 生成规范化签名串：标量字段排序 + timestamp + device_id + key
    function canonical(params) {
        var keys = Object.keys(params).sort();          // 按键名升序
        var pairs = [];                                 // 键值对
        keys.forEach(function (key) {                   // 遍历
            var value = params[key];                    // 取值
            if (typeof value === 'object' && value !== null) { // 数组/对象不参与
                return;                                 // 跳过
            }
            if (value === null || value === undefined) { // 空值
                value = '';                             // 空串
            } else if (typeof value === 'boolean') {    // 布尔
                value = value ? 'true' : 'false';       // 统一格式
            }
            pairs.push(key + '=' + String(value));      // 拼接
        });
        return pairs.join('&') + '&key=' + window.WANYU_ADMIN_SIGN_KEY; // 追加密钥
    }

    // SHA-256（浏览器原生实现，要求 HTTPS 环境）
    async function sha256(text) {
        var data = new TextEncoder().encode(text);      // 转字节
        var buffer = await crypto.subtle.digest('SHA-256', data); // 计算
        return Array.from(new Uint8Array(buffer)).map(function (b) { // 转十六进制
            return b.toString(16).padStart(2, '0');     // 补零
        }).join('');                                    // 拼接
    }

    window.wanyuSignLogin = async function (form) {     // 登录表单签名
        var fields = {};                                // 收集字段
        Array.from(form.elements).forEach(function (el) { // 遍历表单元素
            if (!el.name || el.type === 'submit') {     // 跳过无名/提交按钮
                return;                                 // 继续
            }
            fields[el.name] = el.value;                 // 记录值
        });
        fields['timestamp'] = String(Math.floor(Date.now() / 1000)); // 当前秒级时间
        fields['device_id'] = 'admin-web';              // 固定后台设备标识
        fields['csrf'] = window.WANYU_CSRF;             // CSRF令牌
        var sign = await sha256(canonical(fields));     // 计算签名
        var ts = document.createElement('input');       // 时间戳隐藏域
        ts.type = 'hidden';                             // 隐藏
        ts.name = 'timestamp';                          // 字段名
        ts.value = fields['timestamp'];                 // 值
        form.appendChild(ts);                           // 加入表单
        var dv = document.createElement('input');       // 设备隐藏域
        dv.type = 'hidden';                             // 隐藏
        dv.name = 'device_id';                          // 字段名
        dv.value = 'admin-web';                         // 值
        form.appendChild(dv);                           // 加入表单
        var sg = document.createElement('input');       // 签名隐藏域
        sg.type = 'hidden';                             // 隐藏
        sg.name = 'sign';                               // 字段名
        sg.value = sign;                                // 签名值
        form.appendChild(sg);                           // 加入表单
        return true;                                    // 允许提交
    };

    // 自动绑定带 data-sign 属性的表单
    document.addEventListener('submit', async function (event) { // 监听提交
        var form = event.target;                        // 表单
        if (!form.hasAttribute('data-sign')) {           // 非签名表单
            return;                                     // 放行
        }
        event.preventDefault();                         // 阻止默认提交
        await window.wanyuSignLogin(form);              // 计算签名
        form.submit();                                  // 提交
    });
})();
