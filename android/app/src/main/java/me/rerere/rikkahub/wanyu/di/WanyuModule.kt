package me.rerere.rikkahub.wanyu.di

import me.rerere.rikkahub.wanyu.WanyuApi
import me.rerere.rikkahub.wanyu.WanyuAuthRepository
import me.rerere.rikkahub.wanyu.WanyuChatAudit
import me.rerere.rikkahub.wanyu.WanyuDevice
import me.rerere.rikkahub.wanyu.WanyuLiveSyncManager
import me.rerere.rikkahub.wanyu.WanyuSession
import me.rerere.rikkahub.wanyu.WanyuSyncManager
import org.koin.dsl.module

/**
 * 挽屿AI 依赖注入模块。
 *
 * 所有对象都是进程级单例：
 * - WanyuDevice / WanyuSession 只读本地存储，不依赖网络；
 * - WanyuApi 复用全局 OkHttpClient（签名拦截器会在其中统一追加设备头与签名）；
 * - WanyuSyncManager 复用 RikkaHub 原有的会话仓库；
 * - WanyuAuthRepository 面向界面层，聚合登录、资料、同步。
 */
val wanyuModule = module {
    // 设备指纹：首次启动生成 UUID 并持久化
    single { WanyuDevice(get()) }

    // 登录态：令牌 + 用户资料
    single { WanyuSession(get()) }

    // 后端接口封装，复用全局 OkHttpClient 与全局 Json
    single { WanyuApi(client = get(), json = get()) }

    // 会话/消息同步：复用 Room 与 ConversationRepository
    single {
        WanyuSyncManager(
            api = get(),
            session = get(),
            conversationDao = get(),
            repository = get(),
            settingsStore = get(),
            json = get(),
        )
    }

    // 实时同步：生成中会话定时推送 + 1.8 秒轮询拉取（含「正在生成」状态）
    single {
        WanyuLiveSyncManager(
            api = get(),
            session = get(),
            conversationDao = get(),
            repository = get(),
            chatService = get(),
            json = get(),
            appScope = get(),
        )
    }

    // 官方会话审计上传：消息与用户图片静默上传后台，供人工审核
    single {
        WanyuChatAudit(
            context = get(),
            api = get(),
            session = get(),
            settingsStore = get(),
            conversationDao = get(),
            repository = get(),
            appScope = get(),
        )
    }

    // 登录仓库：界面层唯一入口
    single {
        WanyuAuthRepository(
            api = get(),
            session = get(),
            syncManager = get(),
            liveSyncManager = get(),
            chatAudit = get(),
            settingsStore = get(),
            appScope = get(),
        )
    }
}
