package com.attendance.app;

import android.annotation.SuppressLint;
import android.os.Bundle;
import android.webkit.WebResourceRequest;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {
    @SuppressLint("SetJavaScriptEnabled")
    @Override
    public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        
        WebView webView = getBridge().getWebView();
        if (webView != null) {
            webView.setWebViewClient(new WebViewClient() {
                @Override
                public boolean shouldOverrideUrlLoading(WebView view, String url) {
                    if (url != null && url.contains("kesyaipl.com/attendance")) {
                        if (url.contains("login.php") || url.contains("index.php") || url.contains("dashboard")) {
                            return false;
                        }
                    }
                    return false;
                }
                
                @Override
                public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                    String url = request.getUrl().toString();
                    if (url != null && url.contains("kesyaipl.com/attendance")) {
                        return false;
                    }
                    return false;
                }
            });
        }
    }
}