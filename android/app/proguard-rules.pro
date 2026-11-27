# 挽屿AI：QQ SDK 引用了 OkHttp 旧版的内部类，OkHttp 5 已移除，忽略缺失类导致的 R8 报错
-dontwarn okhttp3.internal.Version
-dontwarn okhttp3.internal.Platform
