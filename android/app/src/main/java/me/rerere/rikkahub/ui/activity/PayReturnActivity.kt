package me.rerere.rikkahub.ui.activity

import android.app.Activity
import android.content.Intent
import android.os.Bundle
import me.rerere.rikkahub.RouteActivity
import me.rerere.rikkahub.wanyu.ui.PAY_RETURN_ORDER_EXTRA

/**
 * 支付完成回跳入口：wanyu://pay/result?out_order_no=xxx
 * 收银台/H5 支付完成后会跳到这里，本页不展示界面，只把订单号交给 RouteActivity 并打开商城页。
 */
class PayReturnActivity : Activity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        // 从深链里取订单号（可能为空，为空时只打开商城页）
        val orderNo = intent?.data?.getQueryParameter("out_order_no").orEmpty()
        val target = Intent(this, RouteActivity::class.java).apply {
            // 复用已有实例，避免重复创建 Activity 堆栈
            addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP)
            putExtra(PAY_RETURN_ORDER_EXTRA, orderNo)          // 商城页据此恢复轮询
        }
        startActivity(target)
        finish()                                               // 自身立即结束，不留在返回栈
    }
}
